<?php
declare(strict_types=1);

$config = require '/var/www/mediawiki/checkmk-status/config.php';

$checkmkBaseUrl = rtrim($config['checkmk_base_url'], '/');
$checkmkUser = $config['checkmk_user'];
$checkmkSecret = $config['checkmk_secret'];
$cacheFile = $config['cache_file'];
$cacheTtl = (int)$config['cache_ttl'];
$timeout = (int)($config['timeout'] ?? 10);
$maxServices = (int)($config['max_services'] ?? 100);
$serviceDisplayNames = $config['service_display_names'] ?? [];

$responseData = null;   
$error = '';
$usedCache = false;
$usedStaleCache = false;
$httpCode = 0;

$cacheDir = dirname($cacheFile);

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function checkmkGetJson(string $url, string $authHeader, int $timeout): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            $authHeader,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    unset($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException(
            'Checkmk API request failed. HTTP code: '
            . $httpCode
            . '. Curl error: '
            . ($curlError ?: 'none')
        );
    }

    $data = json_decode((string)$response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Checkmk API response was not valid JSON.');
    }

    return $data;
}

function findShowServiceUrl(array $serviceRef): ?string {
    $links = $serviceRef['links'] ?? [];

    foreach ($links as $link) {
        if (($link['rel'] ?? '') === 'urn:com.checkmk:rels/show') {
            return $link['href'] ?? null;
        }
    }

    return null;
}

function stateLabel($stateRaw): string {
    $stateMap = [
        0 => 'OK',
        1 => 'WARN',
        2 => 'CRIT',
        3 => 'UNKNOWN',
    ];

    if ($stateRaw === null || $stateRaw === '') {
        return 'N/A';
    }

    return $stateMap[$stateRaw] ?? strtoupper((string)$stateRaw);
}

function stateCssClass(string $state): string {
    return match ($state) {
        'OK' => 'OK',
        'WARN' => 'WARN',
        'CRIT' => 'CRIT',
        'UNKNOWN' => 'UNKNOWN',
        default => 'NA',
    };
}

try {
    if (is_readable($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) {
        $cachedJson = file_get_contents($cacheFile);
        $decodedCache = json_decode((string)$cachedJson, true);

        if (is_array($decodedCache)) {
            $responseData = $decodedCache;
            $usedCache = true;
        }
    }

    if ($responseData === null) {
        $authHeader = 'Authorization: Bearer '
            . trim($checkmkUser)
            . ' '
            . trim($checkmkSecret);

        $collectionUrl = $checkmkBaseUrl . '/domain-types/service/collections/all';

        $collection = checkmkGetJson($collectionUrl, $authHeader, $timeout);
        $serviceRefs = $collection['value'] ?? [];

        $detailedServices = [];
        $failedDetails = [];

        foreach ($serviceRefs as $serviceRef) {
            if (count($detailedServices) >= $maxServices) {
                break;
            }

            $showUrl = findShowServiceUrl($serviceRef);

            if ($showUrl === null) {
                continue;
            }

            try {
                $detail = checkmkGetJson($showUrl, $authHeader, $timeout);
                $detailedServices[] = $detail;
            } catch (Throwable $detailError) {
                $failedDetails[] = [
                    'service' => $serviceRef['title'] ?? $serviceRef['id'] ?? 'unknown service',
                    'error' => $detailError->getMessage(),
                ];
            }
        }

        $responseData = [
            'generated_at' => time(),
            'source_collection_url' => $collectionUrl,
            'total_service_refs_seen' => count($serviceRefs),
            'total_services_rendered' => count($detailedServices),
            'max_services' => $maxServices,
            'failed_details' => $failedDetails,
            'services' => $detailedServices,
        ];

        file_put_contents(
            $cacheFile,
            json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
} catch (Throwable $e) {
    $error = $e->getMessage();

    if (is_readable($cacheFile)) {
        $cachedJson = file_get_contents($cacheFile);
        $decodedCache = json_decode((string)$cachedJson, true);

        if (is_array($decodedCache)) {
            $responseData = $decodedCache;
            $usedCache = true;
            $usedStaleCache = true;
        }
    }
}

header('Content-Type: text/html; charset=utf-8');

$services = $responseData['services'] ?? [];

$hostRegex = trim((string)($_GET['host_regex'] ?? ''));
$serviceRegex = trim((string)($_GET['service_regex'] ?? ''));
$problemsOnly = ($_GET['problems'] ?? '') === '1';

function isSafeRegex(string $regex): bool {
    // Avoid huge patterns.
    if ($regex === '' || strlen($regex) > 200) {
        return false;
    }

    // Basic delimiter safety: we will wrap the pattern in ~...~,
    // so do not allow unescaped ~.
    if (str_contains($regex, '~')) {
        return false;
    }

    // Test that PHP accepts the regex.
    return @preg_match('~' . $regex . '~', '') !== false;
}

if ($hostRegex !== '' && !isSafeRegex($hostRegex)) {
    $hostRegex = '';
}

if ($serviceRegex !== '' && !isSafeRegex($serviceRegex)) {
    $serviceRegex = '';
}

$services = array_values(array_filter($services, function (array $service) use ($hostRegex, $serviceRegex, $problemsOnly): bool {
    $extensions = $service['extensions'] ?? [];

    $hostName = (string)($extensions['host_name'] ?? $service['host_name'] ?? '');
    $description = (string)($extensions['description'] ?? $service['description'] ?? '');

    $stateRaw = $extensions['state'] ?? $service['state'] ?? null;

    if ($problemsOnly && (string)$stateRaw === '0') {
        return false;
    }

    if ($hostRegex !== '' && @preg_match('~' . $hostRegex . '~i', $hostName) !== 1) {
        return false;
    }

    if ($serviceRegex !== '' && @preg_match('~' . $serviceRegex . '~i', $description) !== 1) {
        return false;
    }

    return true;
}));

$generatedAt = $responseData['generated_at'] ?? null;
$failedDetails = $responseData['failed_details'] ?? [];
$totalServiceRefsSeen = $responseData['total_service_refs_seen'] ?? 0;
$totalServicesRendered = $responseData['total_services_rendered'] ?? count($services);

$source = $usedCache ? 'cache' : 'live Checkmk API';

$cacheUpdated = is_readable($cacheFile)
    ? date('Y-m-d H:i:s T', filemtime($cacheFile))
    : 'N/A';

$dataGenerated = $generatedAt
    ? date('Y-m-d H:i:s T', (int)$generatedAt)
    : 'N/A';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="60">
  <title>Checkmk Status</title>
  <style>
    body {
      font-family: system-ui, sans-serif;
      margin: 0;
      padding: 1rem;
      background: #fff;
      color: #222;
    }

    .summary {
      margin-bottom: 1rem;
      font-size: 0.95rem;
      line-height: 1.5;
    }

    .warning {
      border: 1px solid #b26a00;
      background: #fff8e8;
      color: #6f4200;
      padding: 0.75rem;
      border-radius: 6px;
      margin-bottom: 1rem;
    }

    .error {
      border: 1px solid #b00020;
      background: #fff0f0;
      color: #b00020;
      padding: 1rem;
      border-radius: 6px;
      margin-bottom: 1rem;
    }

    table {
      border-collapse: collapse;
      width: 100%;
      font-size: 0.9rem;
    }

    th, td {
      border-bottom: 1px solid #ddd;
      padding: 0.5rem;
      text-align: left;
      vertical-align: top;
    }

    th {
      background: #f5f5f5;
      position: sticky;
      top: 0;
    }

    .OK {
      color: #0a7f22;
      font-weight: 700;
    }

    .WARN {
      color: #b26a00;
      font-weight: 700;
    }

    .CRIT {
      color: #b00020;
      font-weight: 700;
    }

    .UNKNOWN {
      color: #555;
      font-weight: 700;
    }

    .NA {
      color: #777;
      font-weight: 700;
    }
  </style>
</head>
<body>

<?php if ($responseData === null): ?>
  <div class="error">
    <strong>Unable to load Checkmk status.</strong><br>
    Error: <?= h($error ?: 'No response available') ?>
  </div>
<?php else: ?>

  <div class="summary">
    <strong>Checkmk service status</strong><br>
    
    Source: <?= h($source . ($usedStaleCache ? ' - stale fallback' : '')) ?><br>
    Data generated: <?= h($dataGenerated) ?><br>
    Cache updated: <?= h($cacheUpdated) ?><br>
    Page rendered: <?= h(date('Y-m-d H:i:s T')) ?><br>
    Services visible to API user: <?= h((string)$totalServiceRefsSeen) ?><br>
    Services rendered: <?= h((string)$totalServicesRendered) ?><br>
    Max services limit: <?= h((string)$maxServices) ?>
  </div>

  <?php if ($usedStaleCache && $error): ?>
    <div class="warning">
      Using stale cached data because the latest Checkmk API refresh failed.<br>
      <?= h($error) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($failedDetails)): ?>
    <div class="warning">
      Some service detail requests failed: <?= h((string)count($failedDetails)) ?>
    </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>State</th>
        <?php //<th>Host</th> ?>
        <th>Service</th>
        <th>Last Check</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($services as $service): ?>
        <?php
          $extensions = $service['extensions'] ?? [];

          //$hostName = $extensions['host_name'] ?? $service['host_name'] ?? '';
          $description = $extensions['description'] ?? $service['description'] ?? '';
          $displayDescription = $serviceDisplayNames[$description] ?? $description;

          $stateRaw = $extensions['state'] ?? $service['state'] ?? null;
          $state = stateLabel($stateRaw);
          $stateClass = stateCssClass($state);

          $lastCheckRaw = $extensions['last_check'] ?? null;
          $lastCheck = $lastCheckRaw
              ? date('Y-m-d H:i:s T', (int)$lastCheckRaw)
              : 'N/A';
        ?>
        <tr>
          <td class="<?= h($stateClass) ?>"><?= h($state) ?></td>
          <?php /* <td><?= h($hostName)?></td> */ ?>
          <td><?= h($displayDescription) ?></td>
          <td><?= h($lastCheck) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<?php endif; ?>

</body>
</html>