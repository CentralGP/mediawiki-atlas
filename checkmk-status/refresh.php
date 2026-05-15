<?php
declare(strict_types=1);

/*
 * refresh.php
 *
 * This script fetches Checkmk service status data and writes it to services.json.
 * It is intended to be run by cron, not loaded in a browser.
 */

$config = require '/var/www/mediawiki/checkmk-status/config.php';

$checkmkBaseUrl = rtrim($config['checkmk_base_url'], '/');
$checkmkUser = $config['checkmk_user'];
$checkmkSecret = $config['checkmk_secret'];
$cacheFile = $config['cache_file'];
$timeout = (int)($config['timeout'] ?? 10);
$maxServices = (int)($config['max_services'] ?? 100);

$cacheDir = dirname($cacheFile);

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
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
            . '. URL: '
            . $url
        );
    }

    $data = json_decode((string)$response, true);

    if (!is_array($data)) {
        throw new RuntimeException('Checkmk API response was not valid JSON. URL: ' . $url);
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

try {
    $start = microtime(true);

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
            $failedDetails[] = [
                'service' => $serviceRef['title'] ?? $serviceRef['id'] ?? 'unknown service',
                'error' => 'No show_service link found',
            ];
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
        'refresh_seconds' => round(microtime(true) - $start, 2),
        'source_collection_url' => $collectionUrl,
        'total_service_refs_seen' => count($serviceRefs),
        'total_services_rendered' => count($detailedServices),
        'max_services' => $maxServices,
        'failed_details' => $failedDetails,
        'services' => $detailedServices,
    ];

    $tmpFile = $cacheFile . '.tmp';

    file_put_contents(
        $tmpFile,
        json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    rename($tmpFile, $cacheFile);

    echo 'OK: wrote cache file ' . $cacheFile . PHP_EOL;
    echo 'Services rendered: ' . count($detailedServices) . PHP_EOL;
    echo 'Failed details: ' . count($failedDetails) . PHP_EOL;
    echo 'Refresh seconds: ' . $responseData['refresh_seconds'] . PHP_EOL;

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}