<?php

declare(strict_types=1);

require_once __DIR__ . '/sensor_history_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$dataDir = __DIR__ . '/data';
sensor_history_ensure_dir($dataDir);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'series';

if ($action === 'devices') {
    $live = [];
    $stateFile = $dataDir . '/sensors_latest.json';
    if (is_readable($stateFile)) {
        $sj = json_decode(file_get_contents($stateFile), true);
        if (is_array($sj['devices'] ?? null)) {
            $live = array_keys($sj['devices']);
        }
    }
    $history = sensor_history_list_devices($dataDir);
    $merged = array_values(array_unique(array_merge($live, $history)));
    sort($merged);
    echo json_encode(['ok' => true, 'devices' => $merged], JSON_UNESCAPED_UNICODE);
    exit;
}

$device = isset($_GET['device']) ? sensor_history_safe_id((string) $_GET['device']) : '';
if ($device === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing device']);
    exit;
}

$hours = isset($_GET['hours']) ? (int) $_GET['hours'] : 24;
$points = sensor_history_query($dataDir, $device, $hours);

echo json_encode([
    'ok' => true,
    'device' => $device,
    'hours' => max(1, min(168, $hours)),
    'count' => count($points),
    'points' => $points,
], JSON_UNESCAPED_UNICODE);
