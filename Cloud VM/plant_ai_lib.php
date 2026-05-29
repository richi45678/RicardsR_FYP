<?php
/**
 * Plant image analysis via Google Gemini (API key: GEMINI_API_KEY or data/gemini_key.txt).
 */

function plant_ai_meta_path(string $uploadDir, string $basename): string
{
    $metaDir = $uploadDir . '/meta';
    return $metaDir . '/' . $basename . '.json';
}

function plant_ai_get_gemini_key(): ?string
{
    $key = getenv('GEMINI_API_KEY');
    if (is_string($key) && $key !== '') {
        return trim($key);
    }
    $file = __DIR__ . '/data/gemini_key.txt';
    if (is_readable($file)) {
        $line = trim((string) file_get_contents($file));
        if ($line !== '') {
            return $line;
        }
    }
    return null;
}

function plant_ai_instructions_prompt(): string
{
    return <<<TXT
You are an experienced horticulturist. Look at this plant photo. Respond with ONE JSON object only (no markdown), using this schema:
{
  "plant_name": "most likely common name of the plant (or brief description if unknown)",
  "health_status": "healthy" | "needs_attention" | "uncertain",
  "health_summary": "one or two short sentences on overall appearance",
  "diseases_or_concerns": ["list of possible issues, pests, or deficiencies you notice; empty array if none"],
  "watering_estimate": "plain-language guidance on typical watering frequency for this plant in similar conditions (e.g. every 5-7 days); mention it depends on pot, soil, season, and climate",
  "recommended_soil_moisture_min_percent": number from 0 to 100 — minimum soil moisture % to maintain (higher % = wetter soil on capacitive-style inverted scale),
  "disclaimer": "short note that remote photo assessment is not a substitute for in-person expert diagnosis"
}
Be conservative: if unsure, use health_status "uncertain" and explain in health_summary.
Base your answer only on what is visible in the image — do not assume hidden metadata.
TXT;
}

function plant_ai_load_image_parts(string $imageFullPath): array
{
    $raw = file_get_contents($imageFullPath);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'error' => 'empty_image'];
    }
    $mime = 'image/jpeg';
    $fi = new finfo(FILEINFO_MIME_TYPE);
    if ($fi !== false) {
        $detected = $fi->buffer($raw);
        if (is_string($detected) && str_starts_with($detected, 'image/')) {
            $mime = $detected;
        }
    }
    return ['ok' => true, 'mime' => $mime, 'raw' => $raw];
}

function plant_ai_gemini_model_chain(): array
{
    // Prefer current Flash models; avoid gemini-2.0-flash as first choice — free tier often shows quota limit 0.
    $env = getenv('GEMINI_MODEL');
    $fromEnv = is_string($env) && $env !== ''
        ? preg_replace('/[^a-zA-Z0-9._-]/', '', $env)
        : '';
    $fallbacks = [
        'gemini-2.5-flash-lite',
        'gemini-2.5-flash',
        'gemini-1.5-flash',
        'gemini-2.0-flash',
    ];
    $out = [];
    if ($fromEnv !== '') {
        $out[] = $fromEnv;
    }
    foreach ($fallbacks as $m) {
        if (!in_array($m, $out, true)) {
            $out[] = $m;
        }
    }
    return $out;
}

function plant_ai_analyze_gemini(string $imageFullPath, string $apiKey): array
{
    $parts = plant_ai_load_image_parts($imageFullPath);
    if (!$parts['ok']) {
        return $parts;
    }
    $b64 = base64_encode($parts['raw']);

    $bodyBase = [
        'contents' => [
            [
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $parts['mime'],
                            'data' => $b64,
                        ],
                    ],
                    [
                        'text' => plant_ai_instructions_prompt(),
                    ],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.35,
            'maxOutputTokens' => 1024,
            'responseMimeType' => 'application/json',
        ],
    ];

    $lastErr = null;
    $lastBody = null;

    foreach (plant_ai_gemini_model_chain() as $model) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($bodyBase),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $lastErr = 'curl_' . $errno;
            $lastBody = null;
            continue;
        }
        if ($http < 200 || $http >= 300) {
            $lastErr = 'gemini_http_' . $http;
            $lastBody = substr((string) $response, 0, 1200);
            // Retry with another model on quota / not found
            if (in_array($http, [400, 403, 404, 429, 503], true)) {
                continue;
            }
            return ['ok' => false, 'error' => $lastErr, 'body' => $lastBody];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $lastErr = 'gemini_bad_json';
            $lastBody = substr((string) $response, 0, 400);
            continue;
        }

        $text = null;
        if (!empty($decoded['candidates'][0]['content']['parts'])) {
            foreach ($decoded['candidates'][0]['content']['parts'] as $p) {
                if (isset($p['text']) && is_string($p['text'])) {
                    $text = $p['text'];
                    break;
                }
            }
        }
        if (!is_string($text) || $text === '') {
            $reason = $decoded['candidates'][0]['finishReason'] ?? ($decoded['promptFeedback']['blockReason'] ?? 'unknown');
            $lastErr = 'gemini_no_content';
            $lastBody = is_string($reason) ? $reason : json_encode($decoded);
            continue;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            $lastErr = 'model_invalid_json';
            $lastBody = substr($text, 0, 400);
            continue;
        }

        $parsed['analyzed_at'] = gmdate('c');
        $parsed['model'] = 'gemini:' . $model;
        $parsed['provider'] = 'gemini';

        return ['ok' => true, 'data' => $parsed];
    }

    return [
        'ok' => false,
        'error' => $lastErr ?? 'gemini_all_models_failed',
        'body' => $lastBody,
    ];
}

function plant_ai_analyze_image(string $imageFullPath): array
{
    if (!is_file($imageFullPath) || !is_readable($imageFullPath)) {
        return ['ok' => false, 'error' => 'image_not_readable'];
    }

    $key = plant_ai_get_gemini_key();
    if ($key === null) {
        return ['ok' => false, 'error' => 'missing_gemini_key'];
    }

    return plant_ai_analyze_gemini($imageFullPath, $key);
}

function plant_ai_save_meta(string $uploadDir, string $basename, array $payload): bool
{
    $dir = $uploadDir . '/meta';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $path = plant_ai_meta_path($uploadDir, $basename);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return $json !== false && file_put_contents($path, $json) !== false;
}

function plant_ai_load_meta(string $uploadDir, string $basename): ?array
{
    $path = plant_ai_meta_path($uploadDir, $basename);
    if (!is_file($path)) {
        return null;
    }
    $j = json_decode((string) file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function plant_ai_run_and_save(string $imageFullPath): array
{
    $uploadDir = dirname($imageFullPath);
    $basename = basename($imageFullPath);

    $result = plant_ai_analyze_image($imageFullPath);
    if (!$result['ok']) {
        $errPayload = [
            'ok' => false,
            'error' => $result['error'] ?? 'unknown',
            'failed_at' => gmdate('c'),
        ];
        if (isset($result['body'])) {
            $errPayload['detail'] = $result['body'];
        }
        plant_ai_save_meta($uploadDir, $basename, $errPayload);
        return $result;
    }

    $data = $result['data'];
    unset($data['ok']);
    plant_ai_save_meta($uploadDir, $basename, $data);

    require_once __DIR__ . '/monitored_plant_lib.php';
    monitored_plant_apply_ai_recommendation(__DIR__ . '/data', $basename, $data);

    return ['ok' => true, 'data' => $data];
}
