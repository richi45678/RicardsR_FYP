#!/usr/bin/env php
<?php
/**
 * CLI: php plant_analyze_worker.php /full/path/to/image.jpg
 */
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php plant_analyze_worker.php /path/to/image.jpg\n");
    exit(1);
}

$path = $argv[1];
require_once __DIR__ . '/plant_ai_lib.php';

$real = realpath($path);
if ($real === false || !is_file($real)) {
    fwrite(STDERR, "Bad path\n");
    exit(1);
}

$uploadDir = dirname($real);
$base = basename($real);
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed, true)) {
    fwrite(STDERR, "Not an image\n");
    exit(1);
}

$res = plant_ai_run_and_save($real);
exit($res['ok'] ? 0 : 1);
