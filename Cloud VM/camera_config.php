<?php

/**
 * ESP32-CAM capture trigger (used when sensor pump turns ON).
 * Update capture_base_url when your camera ngrok URL changes — match index.php $esp32_devices[].url
 */
return [
    'capture_base_url' => 'https://johnna-nontuned-pentagonally.ngrok-free.dev',
    'http_user' => 'esp32',
    'http_pass' => 'testingCAM',
];
