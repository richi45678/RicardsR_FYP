<?php

declare(strict_types=1);

require_once __DIR__ . '/monitored_plant_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string) $_GET['id'], 0, 32)) : '';
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing id']);
    exit;
}

$dataDir = __DIR__ . '/data';
echo json_encode(monitored_plant_device_config_response($dataDir, $id), JSON_UNESCAPED_UNICODE);
