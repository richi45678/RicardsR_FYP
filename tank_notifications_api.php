<?php

declare(strict_types=1);

require_once __DIR__ . '/tank_notifications_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $cfg = tank_notifications_load($dataDir);
    echo json_encode([
        'ok' => true,
        'notifications' => tank_notifications_public_view($cfg),
        'smtp_configured' => tank_notifications_smtp_configured(),
        'thresholds' => [
            'warning_cm' => TANK_DISTANCE_WARNING_CM,
            'empty_cm' => TANK_DISTANCE_EMPTY_CM,
            'half_cm' => TANK_DISTANCE_HALF_CM,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : 'save';

if ($action === 'test') {
    $email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
    if ($email === '') {
        $cfg = tank_notifications_load($dataDir);
        $email = trim((string) ($cfg['email'] ?? ''));
    }
    $result = tank_notifications_send_test($dataDir, $email);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'save') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_action']);
    exit;
}

$email = isset($_POST['email']) ? trim((string) $_POST['email']) : '';
$enabled = !empty($_POST['enabled']);

if ($email !== '' && !tank_notifications_valid_email($email)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}

if ($enabled && $email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email_required']);
    exit;
}

$cfg = tank_notifications_load($dataDir);
$cfg['email'] = $email;
$cfg['enabled'] = $enabled;

if (!tank_notifications_save($dataDir, $cfg)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save_failed']);
    exit;
}

echo json_encode([
    'ok' => true,
    'notifications' => tank_notifications_public_view($cfg),
], JSON_UNESCAPED_UNICODE);
