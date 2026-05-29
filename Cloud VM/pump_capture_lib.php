<?php

/**
 * Pump ON → server triggers ESP32-CAM /capture; next upload skips plant AI (see upload.php).
 * Keep capture_base_url in sync with index.php $esp32_devices[].url when ngrok changes.
 */

declare(strict_types=1);

function pump_capture_data_dir(string $webRoot): string
{
    return rtrim($webRoot, '/') . '/data';
}

function pump_capture_arm_skip_next_analysis(string $dataDir): void
{
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0755, true);
    }
    $f = $dataDir . '/pump_skip_next_plant_ai.flag';
    file_put_contents($f, (string) time());
}

/**
 * True if this upload should not run plant AI (consumes one-shot flag).
 */
function pump_capture_consume_skip_next_analysis(string $dataDir): bool
{
    $f = $dataDir . '/pump_skip_next_plant_ai.flag';
    if (!is_file($f)) {
        return false;
    }
    $t = (int) trim((string) file_get_contents($f));
    @unlink($f);
    if ($t <= 0) {
        return false;
    }
    // Ignore stale arms (capture failed / never uploaded)
    if (time() - $t > 180) {
        return false;
    }
    return true;
}

function pump_capture_load_config(): ?array
{
    $path = __DIR__ . '/camera_config.php';
    if (!is_file($path)) {
        return null;
    }
    $c = include $path;
    return is_array($c) ? $c : null;
}

/**
 * Non-blocking GET to ESP capture endpoint (same auth/headers as dashboard).
 */
function pump_capture_trigger_async(): void
{
    $cfg = pump_capture_load_config();
    $base = $cfg['capture_base_url'] ?? '';
    $base = is_string($base) ? trim($base) : '';
    if ($base === '') {
        return;
    }

    $user = isset($cfg['http_user']) ? (string) $cfg['http_user'] : 'esp32';
    $pass = isset($cfg['http_pass']) ? (string) $cfg['http_pass'] : 'testingCAM';
    $url = rtrim($base, '/') . '/capture';
    $auth = base64_encode($user . ':' . $pass);

    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $cmd = 'start /B curl -sS -m 120 -G ' . escapeshellarg($url)
            . ' -H ' . escapeshellarg('Authorization: Basic ' . $auth)
            . ' -H ' . escapeshellarg('ngrok-skip-browser-warning: true')
            . ' -H ' . escapeshellarg('User-Agent: Mozilla/5.0')
            . ' -H ' . escapeshellarg('Accept: application/json')
            . ' > NUL 2>&1';
        @pclose(@popen($cmd, 'r'));
        return;
    }

    $cmd = sprintf(
        'curl -sS -m 120 -G %s -H %s -H %s -H %s -H %s >/dev/null 2>&1 &',
        escapeshellarg($url),
        escapeshellarg('Authorization: Basic ' . $auth),
        escapeshellarg('ngrok-skip-browser-warning: true'),
        escapeshellarg('User-Agent: Mozilla/5.0'),
        escapeshellarg('Accept: application/json')
    );
    @exec($cmd);
}
