<?php
declare(strict_types=1);

$config = require '/var/www/mediawiki/checkmk-status/config.php';

$checkmkUrl = $config['checkmk_url'];
$checkmkUser = $config['checkmk_user'];
$checkmkSecret = $config['checkmk_secret'];
$cacheFile = $config['cache_file'];
$cacheTtl = (int)$config['cache_ttl'];
$timeout = (int)($config['timeout'] ?? 10);

$response = null;
$httpCode = 0;
$error = '';
$usedCache = false;
$usedStaleCache = false;

$cacheDir = dirname($cacheFile);

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

if (is_readable($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) {
    $response = file_get_contents($cacheFile);
    $usedCache = true;
} else {
    $authHeader = 'Authorization: Bearer ' . $checkmkUser . ' ' . $checkmkSecret;

    $ch = curl_init($checkmkUrl);

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
    $error = curl_error($ch);

    //curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode((string)$response, true);

        if (is_array($decoded)) {
            file_put_contents($cacheFile, $response, LOCK_EX);
        }
    } elseif (is_readable($cacheFile)) {
        $response = file_get_contents($cacheFile);
        $usedCache = true;
        $usedStaleCache = true;
        $error = 'Using stale cache because Checkmk API request failed.';
    }
}

header('Content-Type: text/html; charset=utf-8');

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
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
    }

    .error {
      border: 1px solid #b00020;
      background: #fff0f0;
      color: #b00020;
      padding: 1rem;
      border-radius: 6px;
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
  </style>
</head>
<body>

<?php if ($response === false || $response === null): ?>
  <div class="error">
    <strong>Unable to load Checkmk status.</strong><br>
    HTTP status: <?= h((string)$httpCode) ?><br>
    Error: <?= h($error ?: 'No response available') ?>
  </div>
<?php else: ?>
  <?php
    $data = json_decode((string)$response, true);

    if (!is_array($data)) {
        $data = [];
    }

    $services = $data['value'] ?? $data['members'] ?? [];

    $stateMap = [
        0 => 'OK',
        1 => 'WARN',
        2 => 'CRIT',
        3 => 'UNKNOWN',
    ];
  ?>

  <?php
    $source = $usedCache ? 'cache' : 'live Checkmk API';
    $cacheUpdated = is_readable($cacheFile)
        ? date('Y-m-d H:i:s T', filemtime($cacheFile))
        : 'N/A';
  ?>
  
  <div class="summary">
    <strong>Checkmk service status</strong><br>
    Source: <?= h($source . ($usedStaleCache ? ' - stale fallback' : '')) ?><br>
    Cache updated: <?= h($cacheUpdated) ?><br>
    Page rendered: <?= h(date('Y-m-d H:i:s T')) ?>
  </div>

  <table>
    <thead>
      <tr>
        <th>State</th>
        <th>Host</th>
        <th>Service</th>
        <th>Output</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($services as $service): ?>
        <?php
          $extensions = $service['extensions'] ?? [];

          $hostName = $extensions['host_name'] ?? $service['host_name'] ?? '';
          $description = $extensions['description'] ?? $service['description'] ?? '';
          $stateRaw = $extensions['state'] ?? $service['state'] ?? null;
          $state = $stateMap[$stateRaw] ?? strtoupper((string)$stateRaw);
          $output = $extensions['plugin_output'] ?? $service['plugin_output'] ?? '';
        ?>
        <tr>
          <td class="<?= h($state) ?>"><?= h($state) ?></td>
          <td><?= h($hostName) ?></td>
          <td><?= h($description) ?></td>
          <td><?= h($output) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

</body>
</html>