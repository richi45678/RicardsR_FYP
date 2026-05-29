<?php
/**
 * POST: filename=esp32_xxx.jpg  — re-run analysis for an existing upload (same AI as new captures).
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$uploadDir = realpath(__DIR__ . '/uploads');
if ($uploadDir === false || !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'uploads_missing']);
    exit;
}

$name = isset($_POST['filename']) ? (string) $_POST['filename'] : '';
$base = basename($name);
if ($base === '' || $base !== $name || strpos($base, "\0") !== false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_filename']);
    exit;
}

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_extension']);
    exit;
}

$full = $uploadDir . DIRECTORY_SEPARATOR . $base;
if (!is_file($full)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

require_once __DIR__ . '/plant_ai_lib.php';

set_time_limit(180);
$result = plant_ai_run_and_save($full);

if (!$result['ok']) {
    echo json_encode([
        'ok' => false,
        'error' => $result['error'] ?? 'analyze_failed',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'plant' => $result['data'],
]);
