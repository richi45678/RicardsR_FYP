<?php

declare(strict_types=1);

/**
 * Synchronous HTTP to ESP32-CAM (dashboard capture/status).
 * Async pump trigger stays in pump_capture_lib.php.
 */

function esp_capture_load_config(): array
{
    $path = __DIR__ . '/camera_config.php';
    if (!is_file($path)) {
        return [];
    }
    $c = include $path;

    return is_array($c) ? $c : [];
}

/** @return list<array{name: string, url: string}> */
function esp_capture_devices(): array
{
    $cfg = esp_capture_load_config();
    $url = trim((string) ($cfg['capture_base_url'] ?? ''));
    if ($url === '') {
        return [];
    }

    return [['name' => 'ESP32-CAM', 'url' => $url]];
}

/** @return array{success: bool, response: string, http_code: int, error: string, url?: string} */
function esp_capture_request(string $baseUrl, string $command): array
{
    $cfg = esp_capture_load_config();
    $user = (string) ($cfg['http_user'] ?? 'esp32');
    $pass = (string) ($cfg['http_pass'] ?? 'testingCAM');
    $auth = base64_encode($user . ':' . $pass);
    $timeout = ($command === 'capture') ? 120 : 20;
    $url = rtrim($baseUrl, '/') . '/' . $command;

    $headers = implode("\r\n", [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0',
        'Authorization: Basic ' . $auth,
        'ngrok-skip-browser-warning: true',
    ]);

    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'ignore_errors' => true, 'method' => 'GET', 'header' => $headers],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false && str_starts_with($url, 'https://')) {
        $httpUrl = 'http://' . substr($url, 8);
        $ctxHttp = stream_context_create([
            'http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "ngrok-skip-browser-warning: true\r\n"],
        ]);
        $response = @file_get_contents($httpUrl, false, $ctxHttp);
        if ($response !== false) {
            $url = $httpUrl;
        }
    }

    if ($response === false) {
        $err = error_get_last();

        return [
            'success' => false,
            'response' => '',
            'http_code' => 0,
            'error' => $err['message'] ?? 'Connection failed',
        ];
    }

    $httpCode = 200;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#HTTP/\d+\.\d+ (\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
                break;
            }
        }
    }

    return [
        'success' => $httpCode === 200,
        'response' => $response,
        'http_code' => $httpCode,
        'error' => '',
        'url' => $url,
    ];
}

/** @param array{name: string, url: string} $device */
function esp_capture_send(array $device, string $command): array
{
    return esp_capture_request((string) ($device['url'] ?? ''), $command);
}
