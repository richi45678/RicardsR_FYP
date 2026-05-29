<?php
/**
 * JSON list of images in uploads/ (newest first). Optional: ?limit=48
 * Each item may include "plant" object from uploads/meta/{name}.json when present.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$uploadDir = __DIR__ . '/uploads';
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 200;

require_once __DIR__ . '/plant_ai_lib.php';

if (!is_dir($uploadDir)) {
    echo json_encode([]);
    exit;
}

$files = [];
foreach (scandir($uploadDir) as $name) {
    if ($name === '.' || $name === '..') {
        continue;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        continue;
    }
    $path = $uploadDir . '/' . $name;
    if (!is_file($path)) {
        continue;
    }
    $row = [
        'name' => $name,
        'mtime' => filemtime($path),
        'size' => filesize($path),
    ];
    $meta = plant_ai_load_meta($uploadDir, $name);
    if ($meta !== null) {
        $row['plant'] = $meta;
    }
    $files[] = $row;
}

usort($files, static function ($a, $b) {
    return $b['mtime'] <=> $a['mtime'];
});

$files = array_slice($files, 0, $limit);
echo json_encode($files);
