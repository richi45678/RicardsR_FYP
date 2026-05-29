<?php
/**
 * GET ?name=filename.jpg — JSON plant meta for one upload (for modal / tools).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$name = isset($_GET['name']) ? (string) $_GET['name'] : '';
$base = basename($name);
if ($base === '' || $base !== $name) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_filename']);
    exit;
}

$uploadDir = realpath(__DIR__ . '/uploads');
if ($uploadDir === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'uploads_missing']);
    exit;
}

require_once __DIR__ . '/plant_ai_lib.php';
$meta = plant_ai_load_meta($uploadDir, $base);
if ($meta === null) {
    echo json_encode(['ok' => true, 'plant' => null]);
    exit;
}

echo json_encode(['ok' => true, 'plant' => $meta]);
