<?php

declare(strict_types=1);

/** Suggested default minimum (user may set lower on the dashboard). */
define('MONITORED_MOISTURE_DEFAULT', 40);

define('MONITORED_DEFAULT_PUMP_MS', 5000);

define('MONITORED_DEFAULT_COOLDOWN_MS', 300000);

function monitored_plant_file(string $dataDir): string
{
    return rtrim($dataDir, '/') . '/monitored_plant.json';
}

function monitored_plant_defaults(): array
{
    return [
        'enabled' => false,
        'photo_filename' => '',
        'esp_id' => '',
        'min_moisture_percent' => MONITORED_MOISTURE_DEFAULT,
        'pump_duration_ms' => MONITORED_DEFAULT_PUMP_MS,
        'auto_irrigate_cooldown_ms' => MONITORED_DEFAULT_COOLDOWN_MS,
        'config_revision' => 0,
        'ai_recommended_min_percent' => null,
        'ai_recommended_at' => null,
        'plant_name' => null,
        'ai_plant_name' => null,
        'updated_at' => null,
        'last_auto_pump_at' => null,
    ];
}

function monitored_plant_load(string $dataDir): array
{
    $path = monitored_plant_file($dataDir);
    if (!is_file($path)) {
        return monitored_plant_defaults();
    }
    $j = json_decode((string) file_get_contents($path), true);
    if (!is_array($j)) {
        return monitored_plant_defaults();
    }

    return array_merge(monitored_plant_defaults(), $j);
}

function monitored_plant_save(string $dataDir, array $cfg, bool $bumpRevision = true): bool
{
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    $cfg['updated_at'] = gmdate('c');
    if ($bumpRevision) {
        $cfg['config_revision'] = (int) ($cfg['config_revision'] ?? 0) + 1;
    }

    return file_put_contents(
        monitored_plant_file($dataDir),
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

function monitored_plant_clamp_min_moisture(int $pct): int
{
    return max(0, min(100, $pct));
}

function monitored_plant_clamp_pump_ms(int $ms): int
{
    return max(1000, min(120000, $ms));
}

function monitored_plant_sanitize_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if (strlen($name) > 80) {
        $name = substr($name, 0, 80);
    }

    return $name;
}

/**
 * Moisture % at or below which the pump runs (user setting from dashboard).
 * AI recommendation is advisory only — shown on the site, not forced onto the ESP.
 */
function monitored_plant_effective_threshold(array $cfg): int
{
    return monitored_plant_clamp_min_moisture((int) ($cfg['min_moisture_percent'] ?? MONITORED_MOISTURE_DEFAULT));
}

/**
 * Config block returned to assigned ESP32 on upload / sensor_config.php.
 */
function monitored_plant_device_config(string $dataDir, string $deviceId): array
{
    require_once __DIR__ . '/sensor_lib.php';

    $cfg = monitored_plant_load($dataDir);
    $assigned = !empty($cfg['enabled'])
        && ($cfg['esp_id'] ?? '') === $deviceId
        && ($cfg['photo_filename'] ?? '') !== '';

    $effective = monitored_plant_effective_threshold($cfg);

    $payload = [
        'assigned' => $assigned,
        'auto_irrigate_enabled' => $assigned,
        'min_moisture_percent' => $effective,
        'moisture_default_percent' => MONITORED_MOISTURE_DEFAULT,
        'ai_recommended_min_percent' => $cfg['ai_recommended_min_percent'],
        'effective_threshold_percent' => $effective,
        'pump_duration_ms' => monitored_plant_clamp_pump_ms((int) ($cfg['pump_duration_ms'] ?? MONITORED_DEFAULT_PUMP_MS)),
        'cooldown_ms' => (int) ($cfg['auto_irrigate_cooldown_ms'] ?? MONITORED_DEFAULT_COOLDOWN_MS),
        'config_revision' => (int) ($cfg['config_revision'] ?? 0),
        'plant_name' => $cfg['plant_name'] ?? null,
        'tank_empty_cm' => TANK_DISTANCE_EMPTY_CM,
        'tank_warning_cm' => TANK_DISTANCE_WARNING_CM,
        'block_pump_when_tank_empty' => true,
    ];

    return $payload;
}

/**
 * JSON for sensor_config.php — flat shape for ESP firmware.
 */
function monitored_plant_device_config_response(string $dataDir, string $deviceId): array
{
    $config = monitored_plant_device_config($dataDir, $deviceId);

    return array_merge(
        ['ok' => true],
        $config,
        ['config' => $config]
    );
}

function monitored_plant_apply_ai_recommendation(string $dataDir, string $photoBasename, array $plantData): void
{
    $cfg = monitored_plant_load($dataDir);
    if (($cfg['photo_filename'] ?? '') !== $photoBasename) {
        return;
    }
    if (!empty($plantData['plant_name'])) {
        $cfg['ai_plant_name'] = monitored_plant_sanitize_name((string) $plantData['plant_name']);
        if (empty($cfg['plant_name'])) {
            $cfg['plant_name'] = $cfg['ai_plant_name'];
        }
    }
    if (isset($plantData['recommended_soil_moisture_min_percent'])) {
        $cfg['ai_recommended_min_percent'] = monitored_plant_clamp_min_moisture(
            (int) $plantData['recommended_soil_moisture_min_percent']
        );
        $cfg['ai_recommended_at'] = gmdate('c');
    }
    monitored_plant_save($dataDir, $cfg);
}

function monitored_plant_meta_ai_name(?array $meta): ?string
{
    if (!is_array($meta) || empty($meta['plant_name']) || (isset($meta['ok']) && $meta['ok'] === false)) {
        return null;
    }

    return monitored_plant_sanitize_name((string) $meta['plant_name']);
}

function monitored_plant_note_auto_pump(string $dataDir, string $deviceId): void
{
    $cfg = monitored_plant_load($dataDir);
    if (($cfg['esp_id'] ?? '') !== $deviceId) {
        return;
    }
    $cfg['last_auto_pump_at'] = gmdate('c');
    monitored_plant_save($dataDir, $cfg, false);
}

/**
 * @return array<string, mixed>
 */
function monitored_plant_public_view(array $cfg, string $uploadDir): array
{
    $photo = (string) ($cfg['photo_filename'] ?? '');
    $meta = null;
    if ($photo !== '' && is_file($uploadDir . '/' . $photo)) {
        require_once __DIR__ . '/plant_ai_lib.php';
        $meta = plant_ai_load_meta($uploadDir, $photo);
    }

    $aiName = $cfg['ai_plant_name'] ?? null;
    if ($aiName === null || $aiName === '') {
        $aiName = monitored_plant_meta_ai_name($meta);
    }
    $displayName = $cfg['plant_name'] ?? null;
    if ($displayName === null || $displayName === '') {
        $displayName = $aiName;
    }

    return [
        'enabled' => !empty($cfg['enabled']),
        'photo_filename' => $photo,
        'photo_url' => $photo !== '' ? ('uploads/' . rawurlencode($photo)) : null,
        'esp_id' => (string) ($cfg['esp_id'] ?? ''),
        'min_moisture_percent' => monitored_plant_clamp_min_moisture((int) ($cfg['min_moisture_percent'] ?? MONITORED_MOISTURE_DEFAULT)),
        'moisture_default_percent' => MONITORED_MOISTURE_DEFAULT,
        'config_revision' => (int) ($cfg['config_revision'] ?? 0),
        'ai_recommended_min_percent' => $cfg['ai_recommended_min_percent'],
        'ai_recommended_at' => $cfg['ai_recommended_at'] ?? null,
        'effective_threshold_percent' => monitored_plant_effective_threshold($cfg),
        'pump_duration_sec' => (int) round(monitored_plant_clamp_pump_ms((int) ($cfg['pump_duration_ms'] ?? MONITORED_DEFAULT_PUMP_MS)) / 1000),
        'plant_name' => $displayName,
        'ai_plant_name' => $aiName,
        'plant_meta' => $meta,
        'last_auto_pump_at' => $cfg['last_auto_pump_at'] ?? null,
        'updated_at' => $cfg['updated_at'] ?? null,
    ];
}
