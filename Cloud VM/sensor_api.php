<?php
/**
 * ESP32 sensor uplink — ephemeral snapshot only (see SENSOR_SNAPSHOT_TTL in sensor_lib.php).
 */
require_once __DIR__ . '/sensor_lib.php';
require_once __DIR__ . '/sensor_history_lib.php';
require_once __DIR__ . '/monitored_plant_lib.php';
require_once __DIR__ . '/tank_notifications_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Sensor-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . '/data';
$stateFile = $dataDir . '/sensors_latest.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function sensor_load_state(string $stateFile): array
{
    if (!file_exists($stateFile)) {
        return ['devices' => []];
    }
    $prev = json_decode(file_get_contents($stateFile), true);
    if (!is_array($prev) || !isset($prev['devices']) || !is_array($prev['devices'])) {
        return ['devices' => []];
    }

    return ['devices' => sensor_prune_devices($prev['devices'])];
}

function sensor_save_state(string $stateFile, array $state): bool
{
    return file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT)) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    if (!is_array($in) || empty($in['id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid json or missing id']);
        exit;
    }

    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', substr((string) $in['id'], 0, 32));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad id']);
        exit;
    }

    $soil = (int) ($in['soil_raw'] ?? 0);
    $dist = array_key_exists('distance_cm', $in) ? (float) $in['distance_cm'] : null;
    $pump = !empty($in['pump_running']);

    $label = sensor_soil_label($soil);

    $state = sensor_load_state($stateFile);
    $prevDevice = isset($state['devices'][$id]) && is_array($state['devices'][$id])
        ? $state['devices'][$id]
        : [];
    $oldPump = false;
    if (isset($prevDevice['pump_running'])) {
        $oldPump = (bool) $prevDevice['pump_running'];
    }

    $state['devices'][$id] = sensor_enrich_device([
        'id' => $id,
        'soil_raw' => $soil,
        'soil_label' => $label,
        'distance_cm' => $dist,
        'pump_running' => $pump,
        'heap' => (int) ($in['heap'] ?? 0),
        'rssi' => (int) ($in['rssi'] ?? 0),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    $state['devices'] = sensor_prune_devices($state['devices']);

    if (!sensor_save_state($stateFile, $state)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'failed to save']);
        exit;
    }

    sensor_history_append($dataDir, $id, $state['devices'][$id]);

    tank_notifications_on_sensor_reading($dataDir, $id, $prevDevice, $state['devices'][$id]);

    $moisturePct = sensor_soil_moisture_percent($soil);
    $deviceConfig = monitored_plant_device_config($dataDir, $id);
    $deviceConfig['current_moisture_percent'] = $moisturePct;
    $deviceConfig['below_threshold'] = $moisturePct < (int) $deviceConfig['effective_threshold_percent'];

    if (!$oldPump && $pump && !empty($deviceConfig['assigned'])) {
        monitored_plant_note_auto_pump($dataDir, $id);
    }

    // Pump OFF → ON: trigger one ESP32-CAM capture; next upload skips plant AI (upload.php).
    if ($pump && !sensor_tank_pump_allowed($dist)) {
        tank_notifications_log($dataDir, "WARN pump reported ON but tank empty (device {$id}, {$dist} cm)");
    }

    if (!$oldPump && $pump) {
        require_once __DIR__ . '/pump_capture_lib.php';
        $cfg = pump_capture_load_config();
        $base = isset($cfg['capture_base_url']) ? trim((string) $cfg['capture_base_url']) : '';
        if ($base !== '') {
            pump_capture_arm_skip_next_analysis($dataDir);
            pump_capture_trigger_async();
        }
    }

    echo json_encode(array_merge(
        ['ok' => true, 'config' => $deviceConfig],
        $deviceConfig
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $state = sensor_load_state($stateFile);
    foreach ($state['devices'] as $did => $row) {
        if (is_array($row)) {
            $state['devices'][$did] = sensor_enrich_device($row);
        }
    }
    sensor_save_state($stateFile, $state);
    echo json_encode($state, JSON_PRETTY_PRINT);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'method not allowed']);
