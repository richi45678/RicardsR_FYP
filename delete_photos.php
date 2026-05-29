<?php
/**
 * Delete selected uploads or all. POST only. Also removes plant meta JSON when present.
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

require_once __DIR__ . '/plant_ai_lib.php';

$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$deleteAll = isset($_POST['delete_all']) && $_POST['delete_all'] === '1';
$selected = isset($_POST['selected']) && is_array($_POST['selected']) ? $_POST['selected'] : [];

$toDelete = [];
if ($deleteAll) {
    foreach (scandir($uploadDir) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }
        $full = $uploadDir . DIRECTORY_SEPARATOR . $name;
        if (is_file($full)) {
            $toDelete[] = $name;
        }
    }
} else {
    foreach ($selected as $name) {
        if (!is_string($name) || $name === '' || strpos($name, "\0") !== false) {
            continue;
        }
        $base = basename($name);
        if ($base !== $name || $base === '.' || $base === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            continue;
        }
        $full = $uploadDir . DIRECTORY_SEPARATOR . $base;
        if (is_file($full)) {
            $toDelete[] = $base;
        }
    }
}

$deleted = 0;
$errors = [];
foreach (array_unique($toDelete) as $name) {
    $full = $uploadDir . DIRECTORY_SEPARATOR . $name;
    if (!is_file($full)) {
        continue;
    }
    $metaPath = plant_ai_meta_path($uploadDir, $name);
    if (is_file($metaPath)) {
        @unlink($metaPath);
    }
    if (@unlink($full)) {
        $deleted++;
    } else {
        $errors[] = $name;
    }
}

echo json_encode([
    'ok' => count($errors) === 0,
    'deleted' => $deleted,
    'errors' => $errors,
]);
