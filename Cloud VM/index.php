<?php
session_start();


require_once __DIR__ . '/esp_capture_lib.php';

$esp32_devices = esp_capture_devices();

// Handle capture request (POST/Redirect/GET so auto-refresh does not re-submit POST)
$capture_result = null;
$selected_camera = null;

if (isset($_POST['capture']) && isset($_POST['camera_index'])) {
    set_time_limit(180);
    $selected_camera = intval($_POST['camera_index']);
    $result = null;
    if (isset($esp32_devices[$selected_camera])) {
        $result = esp_capture_send($esp32_devices[$selected_camera], 'capture');
    }
    $_SESSION['capture_flash'] = [
        'camera_index' => $selected_camera,
        'result' => $result,
    ];
    $redirect = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
    header('Location: ' . $redirect, true, 303);
    exit;
}

if (!empty($_SESSION['capture_flash'])) {
    $flash = $_SESSION['capture_flash'];
    unset($_SESSION['capture_flash']);
    $selected_camera = $flash['camera_index'];
    $capture_result = $flash['result'];
}

// Get camera statuses
$camera_statuses = [];
foreach ($esp32_devices as $index => $camera) {
    $status = esp_capture_send($camera, 'status');
    $camera_statuses[$index] = $status;
}

// Get recent photos (initial render; JS keeps gallery in sync without full page reload)
$photos = glob(__DIR__ . '/uploads/*.jpg');
rsort($photos);
$recent_photos = array_slice($photos, 0, 48);
require_once __DIR__ . '/sensor_lib.php';
require_once __DIR__ . '/sensor_history_lib.php';
require_once __DIR__ . '/plant_ai_lib.php';
require_once __DIR__ . '/monitored_plant_lib.php';
require_once __DIR__ . '/tank_notifications_lib.php';

// Sensor nodes — only show readings newer than SENSOR_SNAPSHOT_TTL (ephemeral cache, not a history log)
$sensor_state_file = __DIR__ . '/data/sensors_latest.json';
$sensor_devices = [];
if (file_exists($sensor_state_file)) {
    $sj = json_decode(file_get_contents($sensor_state_file), true);
    if (is_array($sj) && !empty($sj['devices']) && is_array($sj['devices'])) {
        $sensor_devices = sensor_prune_devices($sj['devices']);
    }
}

$monitored_cfg = monitored_plant_load(__DIR__ . '/data');
$tank_notify_cfg = tank_notifications_load(__DIR__ . '/data');
$tank_notify_view = tank_notifications_public_view($tank_notify_cfg);
$monitored_view = monitored_plant_public_view($monitored_cfg, __DIR__ . '/uploads');
$assigned_esp_moisture = null;
if (!empty($monitored_view['esp_id']) && isset($sensor_devices[$monitored_view['esp_id']])) {
    $assigned_esp_moisture = sensor_soil_moisture_percent((int) ($sensor_devices[$monitored_view['esp_id']]['soil_raw'] ?? 0));
}

$chart_device_ids = array_values(array_unique(array_merge(
    array_keys($sensor_devices),
    sensor_history_list_devices(__DIR__ . '/data')
)));
sort($chart_device_ids);

$cameras_online = 0;
foreach ($camera_statuses as $s) {
    if (!empty($s['success'])) {
        $cameras_online++;
    }
}

$photo_count = count($photos);
$plants_need_attention = 0;
foreach ($recent_photos as $photo) {
    $meta = plant_ai_load_meta(__DIR__ . '/uploads', basename($photo));
    if (is_array($meta) && ($meta['health_status'] ?? '') === 'needs_attention') {
        $plants_need_attention++;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Smart Garden — IoT Irrigation</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="Smart garden monitoring — cameras, plant health AI, soil sensors">
    <meta name="theme-color" content="#0c1210">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
</head>
<body>
    <header class="site-header">
        <div class="navbar">
            <div class="brand">
                <h1>Smart Garden</h1>
                <p>IoT irrigation &amp; plant monitoring</p>
            </div>
            <nav class="section-nav" aria-label="Sections">
                <a href="#cameras">Cameras</a>
                <a href="#photos">Photos</a>
                <a href="#monitored-plant">Monitored</a>
                <a href="#sensors">Sensors</a>
                <a href="#sensor-history">History</a>
                <a href="#alerts">Alerts</a>
            </nav>
        </div>
    </header>

    <div class="container">
        <div class="stats-row" aria-label="Overview">
            <div class="stat-card">
                <div class="stat-label">Cameras online</div>
                <div class="stat-value <?php echo $cameras_online > 0 ? 'stat-ok' : 'stat-muted'; ?>">
                    <?php echo (int) $cameras_online; ?> / <?php echo count($esp32_devices); ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Gallery</div>
                <div class="stat-value"><?php echo (int) $photo_count; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Needs attention</div>
                <div class="stat-value <?php echo $plants_need_attention > 0 ? 'stat-warn' : ''; ?>">
                    <?php echo (int) $plants_need_attention; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Sensor nodes</div>
                <div class="stat-value"><?php echo count($sensor_devices); ?></div>
            </div>
        </div>

        <section id="cameras" class="panel">
            <div class="panel-header">
                <div>
                    <h2>Cameras</h2>
                    <p class="panel-desc">Capture photos remotely. Images upload to the server and run through plant health analysis.</p>
                </div>
            </div>
            <div class="cards-grid">
            <?php foreach ($esp32_devices as $index => $camera):
                $status = $camera_statuses[$index];
                $status_data = $status['success'] ? json_decode($status['response'], true) : null;
                $rssi = isset($status_data['rssi']) ? (int) $status_data['rssi'] : null;
                $rssiClass = 'rssi-fair';
                if ($rssi !== null) {
                    $rssiClass = $rssi >= -60 ? 'rssi-good' : ($rssi >= -75 ? 'rssi-fair' : 'rssi-poor');
                }
            ?>
                <div class="device-card">
                    <div class="device-card-header">
                        <span class="device-name"><?php echo htmlspecialchars($camera['name']); ?></span>
                        <span class="status-badge <?php echo $status['success'] ? 'status-online' : 'status-offline'; ?>">
                            <?php echo $status['success'] ? 'Online' : 'Offline'; ?>
                        </span>
                    </div>

                    <div class="device-details">
                        <?php if ($status['success'] && $status_data): ?>
                            <div class="detail-item">
                                <span class="detail-label">Device ID</span>
                                <span class="detail-value"><?php echo htmlspecialchars($status_data['id'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Local IP</span>
                                <span class="detail-value"><?php echo htmlspecialchars($status_data['ip'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">WiFi signal</span>
                                <span class="detail-value <?php echo $rssiClass; ?>"><?php echo $rssi !== null ? $rssi . ' dBm' : 'N/A'; ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Sensor</span>
                                <span class="detail-value"><?php echo htmlspecialchars($status_data['camera'] ?? 'N/A'); ?></span>
                            </div>
                        <?php else: ?>
                            <div class="detail-item">
                                <span class="detail-label">Status</span>
                                <span class="detail-value"><?php echo htmlspecialchars($status['error'] ?: 'Cannot reach camera — check ngrok or tunnel'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="capture-form">
                        <input type="hidden" name="camera_index" value="<?php echo $index; ?>">
                        <button type="submit" name="capture" class="btn-primary capture-btn"
                                <?php echo !$status['success'] ? 'disabled' : ''; ?>>
                            <span>Capture photo</span>
                        </button>
                    </form>

                    <?php if ($capture_result && $selected_camera == $index): ?>
                        <div class="result-message <?php echo $capture_result['success'] ? 'success' : 'error'; ?>" role="status">
                            <?php if ($capture_result['success']): ?>
                                <strong>Captured</strong> — <?php echo htmlspecialchars($capture_result['response']); ?>
                            <?php else: ?>
                                <strong>Failed</strong> — <?php echo htmlspecialchars($capture_result['error'] ?: 'HTTP ' . $capture_result['http_code']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <section id="photos" class="panel">
            <div class="panel-header">
                <div>
                    <h2>Photo gallery <span class="badge-count" id="gallery-count-badge"><?php echo (int) $photo_count; ?></span></h2>
                    <p class="panel-desc">Tap a photo for AI plant analysis. Gallery refreshes automatically.</p>
                </div>
                <div class="toolbar">
                    <button type="button" class="btn btn-accent" id="gallery-refresh-btn">Refresh</button>
                    <button type="button" class="btn btn-ghost" id="gallery-select-all">Select all</button>
                    <button type="button" class="btn btn-ghost" id="gallery-select-none">Clear</button>
                    <button type="button" class="btn btn-danger" id="gallery-delete-selected" disabled>Delete selected</button>
                    <button type="button" class="btn btn-danger" id="gallery-delete-all">Delete all</button>
                </div>
            </div>
            <p id="gallery-status-msg" class="gallery-status" aria-live="polite"></p>
            <div id="photo-gallery-root">
            <?php if (empty($recent_photos)): ?>
                <div class="empty-state gallery-empty-msg">
                    <strong>No photos yet</strong>
                    Capture from a camera above when it is online.
                </div>
            <?php else: ?>
                <div class="gallery-grid" id="photo-gallery-grid">
                    <?php foreach ($recent_photos as $photo): 
                        $filename = basename($photo);
                        $filesize = filesize($photo);
                        $filetime = filemtime($photo);
                        $fnEsc = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
                        $plantMeta = plant_ai_load_meta(__DIR__ . '/uploads', $filename);
                    ?>
                        <div class="photo-item" data-filename="<?php echo $fnEsc; ?>">
                            <label class="photo-check-label" onclick="event.stopPropagation();">
                                <input type="checkbox" class="photo-cb" value="<?php echo $fnEsc; ?>" onclick="event.stopPropagation(); if (window.galleryOnCheckboxChange) window.galleryOnCheckboxChange();">
                            </label>
                            <div class="photo-thumb-hit" data-filename="<?php echo $fnEsc; ?>" role="button" tabindex="0" title="Enlarge">
                                <img src="uploads/<?php echo rawurlencode($filename); ?>" loading="lazy" alt="">
                                <div class="photo-overlay">
                                    <?php if (is_array($plantMeta) && !empty($plantMeta['plant_name'])): ?>
                                        <div class="plant-line"><?php echo htmlspecialchars($plantMeta['plant_name']); ?></div>
                                        <?php if (!empty($plantMeta['health_status'])): ?>
                                            <?php
                                                $hsClass = preg_replace('/[^a-z_]/i', '', (string) $plantMeta['health_status']);
                                                if ($hsClass === '') {
                                                    $hsClass = 'uncertain';
                                                }
                                            ?>
                                            <span class="plant-pill plant-pill-<?php echo htmlspecialchars($hsClass); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $plantMeta['health_status'])); ?></span>
                                        <?php endif; ?>
                                    <?php elseif (is_array($plantMeta) && isset($plantMeta['ok']) && $plantMeta['ok'] === false): ?>
                                        <div class="plant-line" style="color:#ffb4b4;">AI: error</div>
                                    <?php endif; ?>
                                    <div><?php echo date('H:i:s', $filetime); ?></div>
                                    <div><?php echo round($filesize / 1024); ?> KB</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </section>

        <section id="monitored-plant" class="panel">
            <div class="panel-header">
                <div>
                    <h2>Monitored plant</h2>
                    <p class="panel-desc">Assign a sensor ESP to a gallery photo. The ESP pulls settings from this Azure server over the internet (same as sensor uploads) — it does not need to be on your home Wi‑Fi. After Save, the board checks for changes about every 2 seconds. <strong>Pump is disabled when the water tank reads empty</strong> (≥ <?php echo TANK_DISTANCE_EMPTY_CM; ?> cm).</p>
                </div>
            </div>
            <div id="monitored-plant-root">
                <div class="monitored-layout">
                    <div class="monitored-photo" id="monitored-photo-box">
                        <?php if (!empty($monitored_view['photo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($monitored_view['photo_url']); ?>" alt="Monitored plant" id="monitored-photo-img">
                        <?php else: ?>
                            <div class="empty-state" style="padding:2rem 1rem;font-size:0.85rem;">
                                <strong>No plant selected</strong>
                                Open a photo and use “Set as monitored plant”.
                            </div>
                        <?php endif; ?>
                    </div>
                    <form id="monitored-plant-form" class="monitored-form">
                        <h3 class="monitored-plant-title" id="mp-heading"><?php
                            echo !empty($monitored_view['plant_name'])
                                ? htmlspecialchars((string) $monitored_view['plant_name'])
                                : 'Monitored plant';
                        ?></h3>
                        <div class="form-row">
                            <label for="mp-plant-name">Plant name</label>
                            <input type="text" id="mp-plant-name" name="plant_name" maxlength="80"
                                   placeholder="e.g. Petunia"
                                   value="<?php echo htmlspecialchars((string) ($monitored_view['plant_name'] ?? '')); ?>">
                            <p class="plant-name-hint" id="mp-ai-plant-hint"><?php
                                if (!empty($monitored_view['ai_plant_name'])) {
                                    echo 'AI identified: <strong>' . htmlspecialchars((string) $monitored_view['ai_plant_name']) . '</strong>';
                                    if (($monitored_view['plant_name'] ?? '') !== ($monitored_view['ai_plant_name'] ?? '')) {
                                        echo ' <button type="button" id="mp-use-ai-name">Use AI name</button>';
                                    }
                                } else {
                                    echo 'Run plant AI on this photo to get a suggested name.';
                                }
                            ?></p>
                        </div>
                        <div class="form-row">
                            <label for="mp-photo">Photo file</label>
                            <input type="text" id="mp-photo" name="photo_filename" readonly
                                   value="<?php echo htmlspecialchars($monitored_view['photo_filename'] ?? ''); ?>"
                                   placeholder="Select from gallery modal">
                        </div>
                        <div class="form-row">
                            <label for="mp-esp">Sensor ESP (chip ID)</label>
                            <select id="mp-esp" name="esp_id" required>
                                <option value="">— Select node —</option>
                                <?php foreach ($chart_device_ids as $hid): ?>
                                    <option value="<?php echo htmlspecialchars($hid); ?>"
                                        <?php echo ($monitored_view['esp_id'] ?? '') === $hid ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($hid); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="threshold-grid">
                            <div class="threshold-card">
                                <span class="stat-label">Suggested default</span>
                                <strong><?php echo (int) ($monitored_view['moisture_default_percent'] ?? 40); ?>%</strong>
                            </div>
                            <div class="threshold-card">
                                <span class="stat-label">Your minimum</span>
                                <strong id="mp-display-min"><?php echo (int) $monitored_view['min_moisture_percent']; ?>%</strong>
                            </div>
                            <div class="threshold-card ai">
                                <span class="stat-label">AI recommended</span>
                                <strong id="mp-display-ai"><?php
                                    echo $monitored_view['ai_recommended_min_percent'] !== null
                                        ? (int) $monitored_view['ai_recommended_min_percent'] . '%'
                                        : '—';
                                ?></strong>
                            </div>
                            <div class="threshold-card effective">
                                <span class="stat-label">Pump triggers below</span>
                                <strong id="mp-display-effective"><?php echo (int) $monitored_view['effective_threshold_percent']; ?>%</strong>
                            </div>
                        </div>
                        <?php if ($assigned_esp_moisture !== null): ?>
                            <p class="gallery-status">Live moisture (assigned ESP): <strong><?php echo $assigned_esp_moisture; ?>%</strong></p>
                        <?php endif; ?>
                        <div class="form-row">
                            <label for="mp-min">Minimum moisture % (0–100)</label>
                            <input type="number" id="mp-min" name="min_moisture_percent" min="0" max="100"
                                   value="<?php echo (int) $monitored_view['min_moisture_percent']; ?>">
                        </div>
                        <div class="form-row">
                            <label for="mp-pump">Pump duration (seconds)</label>
                            <input type="number" id="mp-pump" name="pump_duration_sec" min="1" max="120" step="0.5"
                                   value="<?php echo htmlspecialchars((string) $monitored_view['pump_duration_sec']); ?>">
                        </div>
                        <div class="form-row">
                            <label><input type="checkbox" id="mp-enabled" name="enabled" value="1"
                                <?php echo !empty($monitored_view['enabled']) ? 'checked' : ''; ?>> Enable auto-irrigation</label>
                        </div>
                        <div class="toolbar">
                            <button type="submit" class="btn btn-accent">Save monitored plant</button>
                            <button type="button" class="btn btn-ghost" id="mp-clear">Clear</button>
                        </div>
                        <p id="mp-status" class="gallery-status" aria-live="polite"></p>
                    </form>
                </div>
            </div>
        </section>

        <section id="alerts" class="panel">
            <div class="panel-header">
                <div>
                    <h2>Email alerts</h2>
                    <p class="panel-desc">Get emailed when the water tank is <strong>low</strong> (≥ <?php echo TANK_DISTANCE_WARNING_CM; ?> cm) or <strong>empty</strong> (≥ <?php echo TANK_DISTANCE_EMPTY_CM; ?> cm). Alerts repeat at most once per hour (low) or 30 minutes (empty) while the condition continues. Requires <code>notification_config.php</code> on the server with SMTP (e.g. Gmail app password).</p>
                </div>
            </div>
            <form id="tank-notify-form" class="monitored-form" style="max-width: 32rem;">
                <div class="form-row">
                    <label for="notify-email">Notification email</label>
                    <input type="email" id="notify-email" name="email" autocomplete="email"
                           placeholder="you@example.com"
                           value="<?php echo htmlspecialchars($tank_notify_view['email']); ?>">
                </div>
                <div class="form-row">
                    <label>
                        <input type="checkbox" id="notify-enabled" name="enabled" value="1"
                            <?php echo !empty($tank_notify_view['enabled']) ? 'checked' : ''; ?>>
                        Enable tank email alerts
                    </label>
                </div>
                <div class="toolbar">
                    <button type="submit" class="btn btn-accent">Save alerts</button>
                    <button type="button" class="btn btn-ghost" id="notify-test-btn">Send test email</button>
                </div>
                <p id="notify-status" class="gallery-status" aria-live="polite"><?php
                    if (!tank_notifications_smtp_configured()) {
                        echo '<strong style="color:var(--danger);">SMTP not set up on server</strong> — add <code>notification_config.php</code> with Gmail app password (see below). ';
                    }
                    if (!empty($tank_notify_view['last_low_sent'])) {
                        echo 'Last low alert sent: ' . htmlspecialchars($tank_notify_view['last_low_sent']) . ' UTC. ';
                    }
                    if (!empty($tank_notify_view['last_empty_sent'])) {
                        echo 'Last empty alert sent: ' . htmlspecialchars($tank_notify_view['last_empty_sent']) . ' UTC.';
                    }
                ?></p>
            </form>
        </section>

        <section id="sensors" class="panel">
            <div class="panel-header">
                <div>
                    <h2>Sensor nodes</h2>
                    <p class="panel-desc">Live readings and historical charts. Live cards refresh every few seconds.</p>
                </div>
            </div>
            <div id="sensor-nodes-dynamic">
            <?php if (empty($sensor_devices)): ?>
                <div class="empty-state sensor-empty-msg">
                    <strong>No sensor data</strong>
                    Waiting for ESP32 nodes to report in.
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($sensor_devices as $dev):
                        $soilLabel = sensor_soil_label((int) ($dev['soil_raw'] ?? 0));
                        $soilClass = strtolower($soilLabel);
                        if (!in_array($soilClass, ['dry', 'moist', 'wet'], true)) {
                            $soilClass = 'moist';
                        }
                        $soilPct = (int) round(sensor_soil_moisture_percent((int) ($dev['soil_raw'] ?? 0)));
                        $pumpOn = !empty($dev['pump_running']);
                        $distCm = isset($dev['distance_cm']) && is_numeric($dev['distance_cm']) && (float) $dev['distance_cm'] >= 0
                            ? (float) $dev['distance_cm'] : null;
                        $tankPct = sensor_tank_level_percent($distCm);
                        $tankStatus = sensor_tank_status($distCm);
                        $tankClass = strtolower($tankStatus);
                        if (!in_array($tankClass, ['empty', 'low', 'half', 'ok'], true)) {
                            $tankClass = 'noreading';
                        }
                    ?>
                    <div class="device-card">
                        <div class="device-card-header">
                            <span class="device-name">Node <?php echo htmlspecialchars($dev['id'] ?? ''); ?></span>
                            <span class="status-badge status-online">Live</span>
                        </div>
                        <div class="soil-block">
                            <div class="soil-label-row">
                                <span class="detail-label">Soil moisture</span>
                                <span class="soil-tag soil-tag-<?php echo htmlspecialchars($soilClass); ?>"><?php echo htmlspecialchars($soilLabel); ?></span>
                            </div>
                            <div class="soil-bar-track" role="presentation">
                                <div class="soil-bar-fill soil-<?php echo htmlspecialchars($soilClass); ?>" style="width:<?php echo (int) $soilPct; ?>%"></div>
                            </div>
                        </div>
                        <div class="tank-block">
                            <div class="soil-label-row">
                                <span class="detail-label">Water tank</span>
                                <span class="soil-tag tank-tag-<?php echo htmlspecialchars($tankClass); ?>"><?php echo htmlspecialchars(sensor_tank_status_label($tankStatus)); ?></span>
                            </div>
                            <div class="soil-bar-track" role="presentation">
                                <div class="tank-bar-fill tank-<?php echo htmlspecialchars($tankClass); ?>" style="width:<?php echo $tankPct !== null ? (int) round($tankPct) : 0; ?>%"></div>
                            </div>
                        </div>
                        <div class="device-details">
                            <div class="detail-item">
                                <span class="detail-label">Moisture</span>
                                <span class="detail-value"><?php echo sensor_soil_moisture_percent((int) ($dev['soil_raw'] ?? 0)); ?>% <span style="font-weight:400;color:var(--text-muted);">(ADC <?php echo (int) ($dev['soil_raw'] ?? 0); ?>)</span></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Tank level</span>
                                <span class="detail-value"><?php
                                    if ($tankPct === null) {
                                        echo 'No reading';
                                    } else {
                                        echo (int) round($tankPct) . '%';
                                        if ($distCm !== null) {
                                            echo ' <span style="font-weight:400;color:var(--text-muted);">(' . htmlspecialchars((string) round($distCm, 1)) . ' cm)</span>';
                                        }
                                    }
                                    ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Last update</span>
                                <span class="detail-value"><?php echo htmlspecialchars($dev['updated_at'] ?? '—'); ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Pump</span>
                                <span class="detail-value">
                                    <span class="pump-pill <?php echo $pumpOn ? 'pump-on' : 'pump-off'; ?>"><?php echo $pumpOn ? 'Running' : 'Off'; ?></span>
                                    <?php if (!$pumpOn && $tankStatus === 'EMPTY'): ?>
                                        <span class="soil-tag tank-tag-empty" style="margin-left:0.35rem;">Blocked</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">WiFi</span>
                                <span class="detail-value"><?php echo isset($dev['rssi']) ? (int) $dev['rssi'] . ' dBm' : 'N/A'; ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>

            <div class="history-block" id="sensor-history">
                <h3>History &amp; trends</h3>
                <p class="panel-desc" style="margin-top:0.25rem;">Stored automatically when nodes report in (about every 20s). Up to 7 days retained.</p>
                <div class="history-controls">
                    <label for="history-device" class="sr-only">Device</label>
                    <select id="history-device" aria-label="Sensor node">
                        <?php if (empty($chart_device_ids)): ?>
                            <option value="">No devices yet</option>
                        <?php else: ?>
                            <?php foreach ($chart_device_ids as $hid): ?>
                                <option value="<?php echo htmlspecialchars($hid); ?>"><?php echo htmlspecialchars($hid); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="range-btns" role="group" aria-label="Time range">
                        <button type="button" class="history-range" data-hours="6">6 hours</button>
                        <button type="button" class="history-range active" data-hours="24">24 hours</button>
                        <button type="button" class="history-range" data-hours="168">7 days</button>
                    </div>
                </div>
                <p id="history-status" class="history-status" aria-live="polite">Select a node to view charts.</p>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>Soil moisture (%)</h4>
                        <div class="chart-wrap"><canvas id="chartSoil" aria-label="Soil moisture over time"></canvas></div>
                    </div>
                    <div class="chart-card">
                        <h4>Water tank (%)</h4>
                        <p class="panel-desc" style="margin:0 0 0.5rem;font-size:0.75rem;">Empty ≥18.5 cm · Low ≥15 cm · Half ≈10 cm</p>
                        <div class="chart-wrap"><canvas id="chartDistance" aria-label="Water tank level over time"></canvas></div>
                    </div>
                    <div class="chart-card" style="grid-column: 1 / -1; max-width: 100%;">
                        <h4>Pump activity</h4>
                        <div class="chart-wrap chart-wrap-sm"><canvas id="chartPump" aria-label="Pump on or off over time"></canvas></div>
                    </div>
                </div>
            </div>
        </section>

    </div>

    <div id="toast-stack" class="toast-stack" aria-live="polite"></div>

    <div id="imageModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalPlantHeading" onclick="if (event.target === this) closeModal()">
        <div class="modal-plant-dialog" onclick="event.stopPropagation();">
            <button type="button" class="modal-close" onclick="closeModal()" title="Close" aria-label="Close">&times;</button>
            <div class="modal-plant-layout">
                <figure class="modal-plant-figure">
                    <img id="modalImage" src="" alt="Enlarged photo">
                </figure>
                <aside class="modal-plant-aside">
                    <h2 id="modalPlantHeading" class="modal-plant-title">Plant analysis</h2>
                    <p class="modal-plant-file" id="modalPlantFilename"></p>
                    <div id="plantDetailPanel" class="plant-detail-panel" role="region" aria-live="polite">
                        <p class="plant-detail-muted" style="margin:0;">Open a photo from the gallery.</p>
                    </div>
                    <button type="button" id="plantReanalyzeBtn" class="modal-reanalyze-btn">Re-run AI analysis</button>
                    <button type="button" id="setMonitoredPlantBtn" class="modal-monitored-btn">Set as monitored plant</button>
                </aside>
            </div>
        </div>
    </div>

    <script>
    window.SG_CONFIG = <?php
    echo json_encode([
        'tank' => [
            'emptyCm' => TANK_DISTANCE_EMPTY_CM,
            'warningCm' => TANK_DISTANCE_WARNING_CM,
            'halfCm' => TANK_DISTANCE_HALF_CM,
            'fullCm' => TANK_DISTANCE_FULL_CM,
        ],
        'moistureDefault' => MONITORED_MOISTURE_DEFAULT,
    ], JSON_HEX_TAG | JSON_HEX_AMP);
    ?>;
    window.plantMetaByFile = {};
    </script>
    <?php if ($capture_result !== null): ?>
    <script>
        window.__captureFlash = <?php echo json_encode([
            'success' => !empty($capture_result['success']),
            'message' => $capture_result['success']
                ? ($capture_result['response'] ?? 'Photo captured')
                : ($capture_result['error'] ?: 'HTTP ' . ($capture_result['http_code'] ?? '')),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <?php endif; ?>
    <script src="assets/dashboard.js"></script>
</body>
</html>
