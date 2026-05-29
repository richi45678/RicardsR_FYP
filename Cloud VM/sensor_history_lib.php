<?php

declare(strict_types=1);

/** Keep at most 7 days of readings per device. */
define('SENSOR_HISTORY_MAX_AGE_SEC', 7 * 86400);

/** Minimum seconds between stored samples (pump changes always stored). */
define('SENSOR_HISTORY_MIN_INTERVAL_SEC', 20);

/** Cap points returned to the browser per request. */
define('SENSOR_HISTORY_MAX_POINTS', 2000);

function sensor_history_dir(string $dataDir): string
{
    return rtrim($dataDir, '/') . '/sensor_history';
}

function sensor_history_safe_id(string $id): string
{
    return preg_replace('/[^a-zA-Z0-9_-]/', '', substr($id, 0, 32));
}

function sensor_history_path(string $dataDir, string $deviceId): string
{
    $safe = sensor_history_safe_id($deviceId);
    if ($safe === '') {
        return '';
    }

    return sensor_history_dir($dataDir) . '/' . $safe . '.jsonl';
}

function sensor_history_ensure_dir(string $dataDir): void
{
    $dir = sensor_history_dir($dataDir);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

/**
 * @return array{t:int,soil_raw:int,soil_label:string,distance_cm:float|null,pump:int,rssi:int}|null
 */
function sensor_history_read_last_line(string $path): ?array
{
    if (!is_readable($path) || filesize($path) === 0) {
        return null;
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return null;
    }
    $last = null;
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['t'])) {
            $last = $row;
        }
    }
    fclose($fp);

    return $last;
}

/**
 * Append one reading; throttles frequent identical pump-state samples.
 */
function sensor_history_append(string $dataDir, string $deviceId, array $reading): bool
{
    $path = sensor_history_path($dataDir, $deviceId);
    if ($path === '') {
        return false;
    }

    sensor_history_ensure_dir($dataDir);

    $now = time();
    $point = [
        't' => $now,
        'soil_raw' => (int) ($reading['soil_raw'] ?? 0),
        'soil_label' => (string) ($reading['soil_label'] ?? ''),
        'distance_cm' => array_key_exists('distance_cm', $reading) ? $reading['distance_cm'] : null,
        'pump' => !empty($reading['pump_running']) ? 1 : 0,
        'rssi' => (int) ($reading['rssi'] ?? 0),
    ];

    $last = sensor_history_read_last_line($path);
    if ($last !== null) {
        $elapsed = $now - (int) $last['t'];
        $pumpSame = (int) ($last['pump'] ?? 0) === $point['pump'];
        if ($elapsed < SENSOR_HISTORY_MIN_INTERVAL_SEC && $pumpSame) {
            return true;
        }
    }

    $line = json_encode($point, JSON_UNESCAPED_UNICODE) . "\n";
    if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        return false;
    }

    if ($now % 17 === 0) {
        sensor_history_prune_file($path, $now);
    }

    return true;
}

function sensor_history_prune_file(string $path, ?int $now = null): void
{
    if (!is_readable($path)) {
        return;
    }
    $now = $now ?? time();
    $cutoff = $now - SENSOR_HISTORY_MAX_AGE_SEC;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    $kept = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['t'])) {
            continue;
        }
        if ((int) $row['t'] >= $cutoff) {
            $kept[] = json_encode($row, JSON_UNESCAPED_UNICODE);
        }
    }

    $tmp = $path . '.tmp';
    $payload = $kept === [] ? '' : implode("\n", $kept) . "\n";
    if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
        return;
    }
    rename($tmp, $path);
}

/**
 * @return list<string>
 */
function sensor_history_list_devices(string $dataDir): array
{
    $dir = sensor_history_dir($dataDir);
    if (!is_dir($dir)) {
        return [];
    }
    $ids = [];
    foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
        $base = basename($file, '.jsonl');
        if ($base !== '') {
            $ids[] = $base;
        }
    }
    sort($ids);

    return $ids;
}

/**
 * @return list<array{t:int,soil_raw:int,soil_label:string,distance_cm:float|null,pump:int,rssi:int}>
 */
function sensor_history_query(string $dataDir, string $deviceId, int $hours): array
{
    $path = sensor_history_path($dataDir, $deviceId);
    if ($path === '' || !is_readable($path)) {
        return [];
    }

    $hours = max(1, min(168, $hours));
    $since = time() - ($hours * 3600);
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $points = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['t'])) {
            continue;
        }
        if ((int) $row['t'] < $since) {
            continue;
        }
        $points[] = [
            't' => (int) $row['t'],
            'soil_raw' => (int) ($row['soil_raw'] ?? 0),
            'soil_label' => (string) ($row['soil_label'] ?? ''),
            'distance_cm' => isset($row['distance_cm']) ? (is_numeric($row['distance_cm']) ? (float) $row['distance_cm'] : null) : null,
            'pump' => (int) ($row['pump'] ?? 0),
            'rssi' => (int) ($row['rssi'] ?? 0),
        ];
    }

    usort($points, static fn ($a, $b) => $a['t'] <=> $b['t']);

    if (count($points) > SENSOR_HISTORY_MAX_POINTS) {
        $step = (int) ceil(count($points) / SENSOR_HISTORY_MAX_POINTS);
        $down = [];
        foreach ($points as $i => $p) {
            if ($i % $step === 0) {
                $down[] = $p;
            }
        }
        $last = $points[count($points) - 1];
        if ($down[count($down) - 1]['t'] !== $last['t']) {
            $down[] = $last;
        }
        $points = $down;
    }

    return $points;
}
