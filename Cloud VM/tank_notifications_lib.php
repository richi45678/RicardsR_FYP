<?php

declare(strict_types=1);

require_once __DIR__ . '/sensor_lib.php';
require_once __DIR__ . '/tank_notifications_smtp.php';

define('TANK_NOTIFY_COOLDOWN_LOW_SEC', 3600);
define('TANK_NOTIFY_COOLDOWN_EMPTY_SEC', 1800);

function tank_notifications_config_path(string $dataDir): string
{
    return rtrim($dataDir, '/') . '/tank_notifications.json';
}

function tank_notifications_log_path(string $dataDir): string
{
    return rtrim($dataDir, '/') . '/tank_notifications.log';
}

/** @return array{email:string,enabled:bool,devices:array<string,array<string,mixed>>} */
function tank_notifications_defaults(): array
{
    return [
        'email' => '',
        'enabled' => false,
        'devices' => [],
    ];
}

/** @return array{email:string,enabled:bool,devices:array<string,array<string,mixed>>} */
function tank_notifications_load(string $dataDir): array
{
    $path = tank_notifications_config_path($dataDir);
    if (!is_readable($path)) {
        return tank_notifications_defaults();
    }
    $j = json_decode((string) file_get_contents($path), true);
    if (!is_array($j)) {
        return tank_notifications_defaults();
    }
    $out = tank_notifications_defaults();
    $out['email'] = isset($j['email']) ? trim((string) $j['email']) : '';
    $out['enabled'] = !empty($j['enabled']);
    if (isset($j['devices']) && is_array($j['devices'])) {
        $out['devices'] = $j['devices'];
    }

    return $out;
}

/** @param array{email:string,enabled:bool,devices:array<string,array<string,mixed>>} $cfg */
function tank_notifications_save(string $dataDir, array $cfg): bool
{
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    $path = tank_notifications_config_path($dataDir);
    $payload = [
        'email' => trim((string) ($cfg['email'] ?? '')),
        'enabled' => !empty($cfg['enabled']),
        'devices' => is_array($cfg['devices'] ?? null) ? $cfg['devices'] : [],
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    return file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT)) !== false;
}

/** @return array{email:string,enabled:bool,last_low_sent:?string,last_empty_sent:?string} */
function tank_notifications_public_view(array $cfg): array
{
    $lastLow = null;
    $lastEmpty = null;
    foreach ($cfg['devices'] as $dev) {
        if (!is_array($dev)) {
            continue;
        }
        if (!empty($dev['last_low_sent']) && ($lastLow === null || $dev['last_low_sent'] > $lastLow)) {
            $lastLow = (string) $dev['last_low_sent'];
        }
        if (!empty($dev['last_empty_sent']) && ($lastEmpty === null || $dev['last_empty_sent'] > $lastEmpty)) {
            $lastEmpty = (string) $dev['last_empty_sent'];
        }
    }

    return [
        'email' => (string) ($cfg['email'] ?? ''),
        'enabled' => !empty($cfg['enabled']),
        'last_low_sent' => $lastLow,
        'last_empty_sent' => $lastEmpty,
    ];
}

function tank_notifications_valid_email(string $email): bool
{
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** @return array{from_email:string,from_name:string} */
function tank_notifications_mail_identity(): array
{
    $c = tank_notifications_mail_config();
    $fromEmail = trim((string) ($c['from_email'] ?? 'noreply@localhost'));
    $fromName = trim((string) ($c['from_name'] ?? 'Smart Garden'));
    if ($fromEmail === '' && !empty($c['smtp_user'])) {
        $fromEmail = trim((string) $c['smtp_user']);
    }

    return ['from_email' => $fromEmail, 'from_name' => $fromName];
}

function tank_notifications_log(string $dataDir, string $line): void
{
    $msg = gmdate('Y-m-d H:i:s') . ' UTC — ' . $line . "\n";
    @file_put_contents(tank_notifications_log_path($dataDir), $msg, FILE_APPEND);
}

function tank_notifications_send(string $dataDir, string $to, string $subject, string $body): bool
{
    if (tank_notifications_smtp_configured()) {
        $cfg = tank_notifications_mail_config();
        $result = tank_notifications_smtp_send_mail($cfg, $to, $subject, $body);
        $ok = !empty($result['ok']);
        $detail = $ok ? 'SENT via SMTP' : ('SMTP FAILED: ' . ($result['error'] ?? 'unknown'));
        tank_notifications_log($dataDir, "{$detail} to={$to} subject=" . substr($subject, 0, 80));

        return $ok;
    }

    $id = tank_notifications_mail_identity();
    $from = $id['from_name'] . ' <' . $id['from_email'] . '>';
    $headers = 'From: ' . $from . "\r\n";
    $headers .= "Reply-To: {$id['from_email']}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: SmartGarden/1.0\r\n";

    $ok = @mail($to, $subject, $body, $headers);
    tank_notifications_log(
        $dataDir,
        ($ok ? 'SENT' : 'FAILED (no SMTP config — add notification_config.php)') . " to={$to} subject=" . substr($subject, 0, 80)
    );

    return $ok;
}

/**
 * @param array<string, mixed> $prevRow
 * @param array<string, mixed> $newRow
 */
function tank_notifications_on_sensor_reading(
    string $dataDir,
    string $deviceId,
    array $prevRow,
    array $newRow
): void {
    $cfg = tank_notifications_load($dataDir);
    if (!$cfg['enabled'] || !tank_notifications_valid_email($cfg['email'])) {
        return;
    }

    $dist = array_key_exists('distance_cm', $newRow) ? $newRow['distance_cm'] : null;
    if ($dist === null || !is_numeric($dist) || (float) $dist < 0) {
        return;
    }

    $status = sensor_tank_status((float) $dist);
    if ($status !== 'LOW' && $status !== 'EMPTY') {
        if (!isset($cfg['devices'][$deviceId])) {
            $cfg['devices'][$deviceId] = [];
        }
        $cfg['devices'][$deviceId]['last_status'] = $status;
        tank_notifications_save($dataDir, $cfg);

        return;
    }

    $prevStatus = 'OK';
    if (isset($prevRow['tank_status']) && is_string($prevRow['tank_status'])) {
        $prevStatus = $prevRow['tank_status'];
    } elseif (isset($prevRow['distance_cm']) && is_numeric($prevRow['distance_cm'])) {
        $prevStatus = sensor_tank_status((float) $prevRow['distance_cm']);
    }

    $devState = $cfg['devices'][$deviceId] ?? [];
    $now = time();
    $pct = sensor_tank_level_percent((float) $dist);
    $pctStr = $pct !== null ? (string) round($pct, 1) : '?';
    $distStr = round((float) $dist, 1);
    $to = $cfg['email'];

    if ($status === 'LOW') {
        $lastSent = isset($devState['last_low_sent']) ? strtotime((string) $devState['last_low_sent']) : false;
        $cooldown = TANK_NOTIFY_COOLDOWN_LOW_SEC;
        $shouldSend = ($prevStatus !== 'LOW')
            || ($lastSent === false)
            || (($now - $lastSent) >= $cooldown);

        if ($shouldSend) {
            $subject = '[Smart Garden] Water tank low';
            $body = "Water tank level is LOW on sensor node {$deviceId}.\n\n";
            $body .= "Reading: {$distStr} cm (~{$pctStr}% full)\n";
            $body .= "Threshold: warning at " . TANK_DISTANCE_WARNING_CM . " cm or above.\n";
            $body .= "Half full is about " . TANK_DISTANCE_HALF_CM . " cm.\n\n";
            $body .= "Time (UTC): " . gmdate('Y-m-d H:i:s') . "\n";
            $body .= "Dashboard: http://4.233.212.124/#sensors\n";

            if (tank_notifications_send($dataDir, $to, $subject, $body)) {
                $devState['last_low_sent'] = gmdate('Y-m-d\TH:i:s\Z');
            }
        }
    }

    if ($status === 'EMPTY') {
        $lastSent = isset($devState['last_empty_sent']) ? strtotime((string) $devState['last_empty_sent']) : false;
        $cooldown = TANK_NOTIFY_COOLDOWN_EMPTY_SEC;
        $shouldSend = ($prevStatus !== 'EMPTY')
            || ($lastSent === false)
            || (($now - $lastSent) >= $cooldown);

        if ($shouldSend) {
            $subject = '[Smart Garden] CRITICAL — Water tank empty';
            $body = "CRITICAL: Water tank appears EMPTY on sensor node {$deviceId}.\n\n";
            $body .= "Reading: {$distStr} cm (~{$pctStr}% full)\n";
            $body .= "Empty threshold: " . TANK_DISTANCE_EMPTY_CM . " cm or above.\n\n";
            $body .= "Refill the tank as soon as possible.\n\n";
            $body .= "Time (UTC): " . gmdate('Y-m-d H:i:s') . "\n";
            $body .= "Dashboard: http://4.233.212.124/#sensors\n";

            if (tank_notifications_send($dataDir, $to, $subject, $body)) {
                $devState['last_empty_sent'] = gmdate('Y-m-d\TH:i:s\Z');
            }
        }
    }

    $devState['last_status'] = $status;
    $cfg['devices'][$deviceId] = $devState;
    tank_notifications_save($dataDir, $cfg);
}

function tank_notifications_send_test(string $dataDir, string $email): array
{
    if (!tank_notifications_valid_email($email)) {
        return ['ok' => false, 'error' => 'invalid_email'];
    }
    $subject = '[Smart Garden] Test notification';
    $body = "This is a test email from your Smart Garden dashboard.\n\n";
    $body .= "If you received this, alert delivery is working.\n";
    $body .= "Low alerts: ≥ " . TANK_DISTANCE_WARNING_CM . " cm\n";
    $body .= "Critical (empty): ≥ " . TANK_DISTANCE_EMPTY_CM . " cm\n\n";
    $body .= "Time (UTC): " . gmdate('Y-m-d H:i:s') . "\n";

    $sent = tank_notifications_send($dataDir, $email, $subject, $body);

    if ($sent) {
        return ['ok' => true, 'message' => 'Test email sent (check spam if not in inbox).'];
    }
    $hint = tank_notifications_smtp_configured()
        ? 'SMTP send failed — check username/app password in notification_config.php and data/tank_notifications.log.'
        : 'SMTP not configured — create /var/www/html/notification_config.php with Gmail (or other) SMTP settings. See notification_config.php.example.';

    return ['ok' => false, 'error' => 'mail_failed', 'message' => $hint];
}
