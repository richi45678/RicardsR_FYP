<?php

declare(strict_types=1);

require_once __DIR__ . '/monitored_plant_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . '/data';
$uploadDir = __DIR__ . '/uploads';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cfg = monitored_plant_load($dataDir);
    echo json_encode([
        'ok' => true,
        'monitored' => monitored_plant_public_view($cfg, $uploadDir),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';

if ($action === 'clear') {
    monitored_plant_save($dataDir, monitored_plant_defaults());
    echo json_encode(['ok' => true, 'monitored' => monitored_plant_public_view(monitored_plant_load($dataDir), $uploadDir)]);
    exit;
}

if ($action !== 'save') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_action']);
    exit;
}

$photo = isset($_POST['photo_filename']) ? basename((string) $_POST['photo_filename']) : '';
$espId = isset($_POST['esp_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string) $_POST['esp_id'], 0, 32)) : '';
$minPct = isset($_POST['min_moisture_percent']) ? (int) $_POST['min_moisture_percent'] : MONITORED_MOISTURE_DEFAULT;
$pumpSec = isset($_POST['pump_duration_sec']) ? (float) $_POST['pump_duration_sec'] : 5.0;
$enabled = !empty($_POST['enabled']);
$plantNameIn = array_key_exists('plant_name', $_POST)
    ? monitored_plant_sanitize_name((string) $_POST['plant_name'])
    : null;

if ($photo === '' || strpos($photo, "\0") !== false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'photo_required']);
    exit;
}

$full = $uploadDir . '/' . $photo;
if (!is_file($full)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'photo_not_found']);
    exit;
}

if ($espId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'esp_required']);
    exit;
}

$cfg = monitored_plant_load($dataDir);
$cfg['enabled'] = $enabled;
$cfg['photo_filename'] = $photo;
$cfg['esp_id'] = $espId;
$cfg['min_moisture_percent'] = monitored_plant_clamp_min_moisture($minPct);
$cfg['pump_duration_ms'] = monitored_plant_clamp_pump_ms((int) round($pumpSec * 1000));

require_once __DIR__ . '/plant_ai_lib.php';
$meta = plant_ai_load_meta($uploadDir, $photo);
$aiName = monitored_plant_meta_ai_name($meta);
if ($aiName !== null) {
    $cfg['ai_plant_name'] = $aiName;
}
if ($plantNameIn !== null && $plantNameIn !== '') {
    $cfg['plant_name'] = $plantNameIn;
} elseif ($aiName !== null) {
    $cfg['plant_name'] = $aiName;
}
if (is_array($meta) && isset($meta['recommended_soil_moisture_min_percent'])) {
    $cfg['ai_recommended_min_percent'] = monitored_plant_clamp_min_moisture((int) $meta['recommended_soil_moisture_min_percent']);
    $cfg['ai_recommended_at'] = gmdate('c');
}

if (!monitored_plant_save($dataDir, $cfg)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

echo json_encode([
    'ok' => true,
    'monitored' => monitored_plant_public_view($cfg, $uploadDir),
], JSON_UNESCAPED_UNICODE);
