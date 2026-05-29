<?php

declare(strict_types=1);

/**
 * Minimal SMTP client (AUTH LOGIN + STARTTLS). Used when notification_config.php has smtp_host set.
 */

/** @return array<string, mixed> */
function tank_notifications_mail_config(): array
{
    $cfg = [
        'from_email' => 'noreply@localhost',
        'from_name' => 'Smart Garden',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_user' => '',
        'smtp_pass' => '',
    ];
    $path = __DIR__ . '/notification_config.php';
    if (!is_readable($path)) {
        return $cfg;
    }
    $local = require $path;
    if (!is_array($local)) {
        return $cfg;
    }

    return array_merge($cfg, $local);
}

function tank_notifications_smtp_configured(): bool
{
    $c = tank_notifications_mail_config();
    $pass = (string) ($c['smtp_pass'] ?? '');
    if ($pass === '' || stripos($pass, 'REPLACE_WITH') !== false || stripos($pass, 'your-16-char') !== false) {
        return false;
    }

    return trim((string) ($c['smtp_host'] ?? '')) !== ''
        && trim((string) ($c['smtp_user'] ?? '')) !== '';
}

/** @return array{ok:bool,error?:string} */
function tank_notifications_smtp_read($fp): array
{
    $lines = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $lines .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    if ($lines === '') {
        return ['ok' => false, 'error' => 'no SMTP response'];
    }
    $code = (int) substr($lines, 0, 3);
    if ($code >= 400) {
        return ['ok' => false, 'error' => trim($lines)];
    }

    return ['ok' => true, 'lines' => $lines];
}

/** @return array{ok:bool,error?:string} */
function tank_notifications_smtp_cmd($fp, string $cmd, array $expectCodes = []): array
{
    if ($cmd !== '') {
        fwrite($fp, $cmd . "\r\n");
    }
    $r = tank_notifications_smtp_read($fp);
    if (!$r['ok']) {
        return $r;
    }
    if ($expectCodes !== []) {
        $code = (int) substr((string) $r['lines'], 0, 3);
        if (!in_array($code, $expectCodes, true)) {
            return ['ok' => false, 'error' => 'unexpected: ' . trim((string) $r['lines'])];
        }
    }

    return $r;
}

/**
 * @param array<string, mixed> $cfg
 * @return array{ok:bool,error?:string}
 */
function tank_notifications_smtp_send_mail(
    array $cfg,
    string $to,
    string $subject,
    string $body
): array {
    $host = trim((string) $cfg['smtp_host']);
    $port = (int) ($cfg['smtp_port'] ?? 587);
    $enc = strtolower(trim((string) ($cfg['smtp_encryption'] ?? 'tls')));
    $user = trim((string) $cfg['smtp_user']);
    $pass = (string) ($cfg['smtp_pass']);
    $fromEmail = trim((string) $cfg['from_email']);
    if ($fromEmail === '') {
        $fromEmail = $user;
    }
    $fromName = trim((string) ($cfg['from_name'] ?? 'Smart Garden'));

    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);
    $fp = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if ($fp === false) {
        return ['ok' => false, 'error' => "connect failed: {$errstr} ({$errno})"];
    }
    stream_set_timeout($fp, 20);

    $r = tank_notifications_smtp_read($fp);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }

    $ehloHost = 'smartgarden.local';
    $r = tank_notifications_smtp_cmd($fp, 'EHLO ' . $ehloHost, [250]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }

    if ($enc === 'tls') {
        $r = tank_notifications_smtp_cmd($fp, 'STARTTLS', [220]);
        if (!$r['ok']) {
            fclose($fp);

            return $r;
        }
        $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            fclose($fp);

            return ['ok' => false, 'error' => 'STARTTLS failed'];
        }
        $r = tank_notifications_smtp_cmd($fp, 'EHLO ' . $ehloHost, [250]);
        if (!$r['ok']) {
            fclose($fp);

            return $r;
        }
    }

    $r = tank_notifications_smtp_cmd($fp, 'AUTH LOGIN', [334]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }
    $r = tank_notifications_smtp_cmd($fp, base64_encode($user), [334]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }
    $r = tank_notifications_smtp_cmd($fp, base64_encode($pass), [235]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }

    $r = tank_notifications_smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }
    $r = tank_notifications_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }
    $r = tank_notifications_smtp_cmd($fp, 'DATA', [354]);
    if (!$r['ok']) {
        fclose($fp);

        return $r;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromHeader = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>'
        : $fromEmail;

    $data = 'From: ' . $fromHeader . "\r\n";
    $data .= 'To: <' . $to . ">\r\n";
    $data .= 'Subject: ' . $encodedSubject . "\r\n";
    $data .= "MIME-Version: 1.0\r\n";
    $data .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $data .= "Content-Transfer-Encoding: 8bit\r\n";
    $data .= "\r\n";
    $data .= preg_replace("/\r\n|\r|\n/", "\r\n", $body);
    $data .= "\r\n.\r\n";

    fwrite($fp, $data);
    $r = tank_notifications_smtp_read($fp);
    tank_notifications_smtp_cmd($fp, 'QUIT', [221]);
    fclose($fp);

    if (!$r['ok']) {
        return $r;
    }
    $code = (int) substr((string) ($r['lines'] ?? ''), 0, 3);
    if ($code !== 250) {
        return ['ok' => false, 'error' => 'DATA rejected: ' . trim((string) ($r['lines'] ?? ''))];
    }

    return ['ok' => true];
}
