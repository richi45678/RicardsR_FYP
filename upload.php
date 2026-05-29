<?php
// upload.php - Receive photos from ESP32-CAM

require_once __DIR__ . '/pump_capture_lib.php';

$upload_dir = __DIR__ . '/uploads/';

// Get ESP32 ID from header
$esp32_id = $_SERVER['HTTP_X_ESP32_ID'] ?? 'unknown';

// Create upload directory if needed
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Get image data
$image_data = file_get_contents('php://input');

if (empty($image_data)) {
    http_response_code(400);
    die('No image data');
}

// Generate filename with ESP32 ID and timestamp
$timestamp = date('Y-m-d_H-i-s');
$filename = $upload_dir . "esp32_{$esp32_id}_{$timestamp}.jpg";

// Save image
if (file_put_contents($filename, $image_data)) {
    // Log the upload
    $log = date('Y-m-d H:i:s') . " - Photo from ESP32: {$esp32_id} - Size: " . strlen($image_data) . " bytes\n";
    file_put_contents($upload_dir . 'upload.log', $log, FILE_APPEND);

    echo "Photo saved successfully";

    $skipPlantAi = false;
    if (isset($_SERVER['HTTP_X_PLANT_AI']) && strcasecmp((string) $_SERVER['HTTP_X_PLANT_AI'], 'skip') === 0) {
        $skipPlantAi = true;
    }
    if (!$skipPlantAi && pump_capture_consume_skip_next_analysis(pump_capture_data_dir(__DIR__))) {
        $skipPlantAi = true;
    }

    // Background plant analysis; skipped for pump-triggered captures (see sensor_api.php + pump_capture_lib.php)
    $worker = __DIR__ . '/plant_analyze_worker.php';
    if (!$skipPlantAi && is_file($worker)) {
        $phpBin = (defined('PHP_BINARY') && PHP_BINARY && is_executable(PHP_BINARY))
            ? PHP_BINARY
            : '/usr/bin/php';
        $cmd = escapeshellcmd($phpBin) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($filename);
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            pclose(popen('start /B ' . $cmd, 'r'));
        } else {
            exec($cmd . ' >> ' . escapeshellarg($upload_dir . 'plant_worker.log') . ' 2>&1 &');
        }
    }
} else {
    http_response_code(500);
    echo "Failed to save photo";
}
