<?php

declare(strict_types=1);

/**
 * Ephemeral sensor cache: readings older than TTL are dropped (no historical log).
 */
define('SENSOR_SNAPSHOT_TTL', 300);

/**
 * Capacitive soil sensor on ESP32 ADC: lower raw = wetter, higher raw = drier.
 * Thresholds must match the sensor node's firmware.
 */
function sensor_soil_label(int $soilRaw): string
{
    if ($soilRaw < 1500) {
        return 'WET';
    }
    if ($soilRaw < 3000) {
        return 'MOIST';
    }

    return 'DRY';
}

function sensor_soil_moisture_percent(int $soilRaw): float
{
    $clamped = max(0, min(4095, $soilRaw));

    return round((4095 - $clamped) / 4095.0 * 100.0, 1);
}

/**
 * HC-SR04 over water tank: larger distance (cm) = less water.
 * Calibrated: ~20 cm = bottom, 18.5 cm = empty, 15 cm = low warning, 10 cm = half.
 */
define('TANK_DISTANCE_EMPTY_CM', 18.5);
define('TANK_DISTANCE_WARNING_CM', 15.0);
define('TANK_DISTANCE_HALF_CM', 10.0);
define('TANK_DISTANCE_FULL_CM', 1.5);

function sensor_tank_level_percent(?float $distanceCm): ?float
{
    if ($distanceCm === null || $distanceCm < 0) {
        return null;
    }
    $d = $distanceCm;
    if ($d >= TANK_DISTANCE_EMPTY_CM) {
        return 0.0;
    }
    if ($d <= TANK_DISTANCE_FULL_CM) {
        return 100.0;
    }
    if ($d <= TANK_DISTANCE_HALF_CM) {
        $range = TANK_DISTANCE_HALF_CM - TANK_DISTANCE_FULL_CM;

        return round(50.0 + (TANK_DISTANCE_HALF_CM - $d) / $range * 50.0, 1);
    }
    $range = TANK_DISTANCE_EMPTY_CM - TANK_DISTANCE_HALF_CM;

    return round((TANK_DISTANCE_EMPTY_CM - $d) / $range * 50.0, 1);
}

/** @return string EMPTY|LOW|HALF|OK|NO_READING */
function sensor_tank_status(?float $distanceCm): string
{
    if ($distanceCm === null || $distanceCm < 0) {
        return 'NO_READING';
    }
    $d = $distanceCm;
    if ($d >= TANK_DISTANCE_EMPTY_CM) {
        return 'EMPTY';
    }
    if ($d >= TANK_DISTANCE_WARNING_CM) {
        return 'LOW';
    }
    if ($d >= TANK_DISTANCE_HALF_CM) {
        return 'HALF';
    }

    return 'OK';
}

function sensor_tank_status_label(string $status): string
{
    return match ($status) {
        'EMPTY' => 'Empty',
        'LOW' => 'Low',
        'HALF' => 'Half',
        'OK' => 'OK',
        'NO_READING' => 'No reading',
        default => $status,
    };
}

/** True when ultrasonic reading means reservoir is empty — pump must not run. */
function sensor_tank_is_empty(?float $distanceCm): bool
{
    return $distanceCm !== null && $distanceCm >= 0 && $distanceCm >= TANK_DISTANCE_EMPTY_CM;
}

/** False only when tank is confirmed empty; blocks auto and manual pump on ESP. */
function sensor_tank_pump_allowed(?float $distanceCm): bool
{
    return !sensor_tank_is_empty($distanceCm);
}

/** @param array<string, mixed> $row */
function sensor_enrich_device(array $row): array
{
    $dist = array_key_exists('distance_cm', $row) ? $row['distance_cm'] : null;
    if ($dist !== null && is_numeric($dist) && (float) $dist >= 0) {
        $cm = (float) $dist;
        $row['tank_level_percent'] = sensor_tank_level_percent($cm);
        $row['tank_status'] = sensor_tank_status($cm);
        $row['pump_allowed'] = sensor_tank_pump_allowed($cm);
    } else {
        $row['tank_level_percent'] = null;
        $row['tank_status'] = 'NO_READING';
        $row['pump_allowed'] = true;
    }

    return $row;
}

function sensor_prune_devices(array $devices, ?int $now = null): array
{
    $now = $now ?? time();
    $out = [];

    foreach ($devices as $id => $row) {
        if (!is_array($row) || empty($row['updated_at'])) {
            continue;
        }
        $t = strtotime((string) $row['updated_at']);
        if ($t === false) {
            continue;
        }
        if (($now - $t) <= SENSOR_SNAPSHOT_TTL) {
            $out[$id] = $row;
        }
    }

    return $out;
}
