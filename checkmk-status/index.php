<?php
declare(strict_types=1);

/*
 * index.php
 *
 * This page renders Checkmk status from the local cache file.
 * It does not call the Checkmk API directly.
 * refresh.php updates the cache on a cron schedule.
 */

$config = require '/var/www/mediawiki/checkmk-status/config.php';

$cacheFile = $config['cache_file'];
$serviceDisplayNames = $config['service_display_names'] ?? [];

$responseData = null;
$error = '';

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

if (is_readable($cacheFile)) {
    $cachedJson = file_get_contents($cacheFile);
    $decodedCache = json_decode((string)$cachedJson, true);

    if (is_array($decodedCache)) {
        $responseData = $decodedCache;
    } else {
        $error = 'Cache file exists but does not contain valid JSON.';
    }
} else {
    $error = 'Cache file does not exist yet. Run refresh.php or wait for cron.';
}

header('Content-Type: text/html; charset=utf-8');

$services = $responseData['services'] ?? [];

$hostRegex = trim((string)($_GET['host_regex'] ?? ''));
$serviceRegex = trim((string)($_GET['service_regex'] ?? ''));
$problemsOnly = ($_GET['problems'] ?? '') === '1';

function isSafeRegex(string $regex): bool {
    if ($regex === '' || strlen($regex) > 200) {
        return false;
    }

    if (str_contains($regex, '~')) {
        return false;
    }

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
$refreshSeconds = $responseData['refresh_seconds'] ?? 'N/A';

$cacheAgeSeconds = is_readable($cacheFile)
    ? time() - filemtime($cacheFile)
    : null;

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
  <meta http-equiv="refresh" content="300">
  <title>Checkmk Status</title>
  <style>
    body {
      font-family: system-ui, sans-serif;
      margin: 0;
      padding: 1rem;
      background: #fff;
      color: #222;
    }

    .page-title {
    margin: 0 0 1rem 0;
    font-size: 1.4rem;
    font-weight: 700;
    }
    
    .summary {
    margin-top: 1rem;
    margin-bottom: 0;
    padding-top: 0.75rem;
    border-top: 1px solid #ddd;
    font-size: 0.85rem;
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
  <h1 class="page-title">Checkmk Server Status</h1>

  <?php if (!empty($failedDetails)): ?>
    <div class="warning">
      Some service detail requests failed: <?= h((string)count($failedDetails)) ?>
    </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>State</th>
        <th>Service</th>
        <th>Last Check</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($services as $service): ?>
        <?php
            $extensions = $service['extensions'] ?? [];

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
            <td><?= h($displayDescription) ?></td>
            <td><?= h($lastCheck) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
    <div class="summary">
        <?php if ($hostRegex !== '' || $serviceRegex !== '' || $problemsOnly): ?>
          Filters:
          <?= $hostRegex !== '' ? ' host_regex=' . h($hostRegex) : '' ?>
          <?= $serviceRegex !== '' ? ' service_regex=' . h($serviceRegex) : '' ?>
          <?= $problemsOnly ? ' problems=1' : '' ?>
          <br>
        <?php endif; ?>
        Cache age: <?= h($cacheAgeSeconds === null ? 'N/A' : (string)$cacheAgeSeconds . ' seconds') ?><br>
        Data generated: <?= h($dataGenerated) ?><br>
        Cache updated: <?= h($cacheUpdated) ?><br>
        Services visible to API user: <?= h((string)$totalServiceRefsSeen) ?><br>
        Services rendered before page filters: <?= h((string)$totalServicesRendered) ?><br>
        Services shown on this page: <?= h((string)count($services)) ?><br>
        Refresh duration: <?= h((string)$refreshSeconds) ?> seconds
    </div>
<?php endif; ?>

</body>
</html>