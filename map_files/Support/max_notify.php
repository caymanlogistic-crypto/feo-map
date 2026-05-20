<?php

if (!function_exists('mapError')) {
    function mapError(string $message, array $context = []): void
    {
        error_log('[map:max_notify] ' . $message . (empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE)));
    }
}

function mapGetNotifyConfig(): array
{
    $configPath = dirname(__DIR__) . '/config/max_notify.php';
    $cfg = [];
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $cfg = $loaded;
        }
    }

    return [
        'key' => isset($cfg['key']) ? trim((string)$cfg['key']) : '',
        'base_url' => isset($cfg['base_url']) ? trim((string)$cfg['base_url']) : '',
        'token' => isset($cfg['token']) ? trim((string)$cfg['token']) : '',
        'chat_id' => isset($cfg['chat_id']) ? trim((string)$cfg['chat_id']) : '',
    ];
}

function mapGetNotifyKey(): string
{
    $cfg = mapGetNotifyConfig();
    if ($cfg['key'] !== '') {
        return $cfg['key'];
    }

    $envKey = getenv('MAX_NOTIFY_KEY');
    if (is_string($envKey) && trim($envKey) !== '') {
        return trim($envKey);
    }
    if (!empty($_SERVER['MAX_NOTIFY_KEY'])) {
        return (string)$_SERVER['MAX_NOTIFY_KEY'];
    }
    if (!empty($_ENV['MAX_NOTIFY_KEY'])) {
        return (string)$_ENV['MAX_NOTIFY_KEY'];
    }
    global $maxNotifyKey;
    if (!empty($maxNotifyKey)) {
        return (string)$maxNotifyKey;
    }
    return '';
}

function mapGetDirectMaxConfig(): array
{
    $cfg = mapGetNotifyConfig();
    $baseUrl = $cfg['base_url'];
    $token = $cfg['token'];
    $chatId = $cfg['chat_id'];

    if ($baseUrl === '') {
        $baseUrl = trim((string)(getenv('MAX_API_BASE_URL') ?: ($_SERVER['MAX_API_BASE_URL'] ?? '')));
    }
    if ($token === '') {
        $token = trim((string)(getenv('MAX_API_TOKEN') ?: ($_SERVER['MAX_API_TOKEN'] ?? '')));
    }
    if ($chatId === '') {
        $chatId = trim((string)(getenv('MAX_CHAT_ID') ?: ($_SERVER['MAX_CHAT_ID'] ?? '')));
    }

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'token' => $token,
        'chat_id' => rtrim($chatId, '; '),
    ];
}

function normalizeUtf8Message(string $text): string
{
    $message = preg_replace('/^\xEF\xBB\xBF/u', '', $text ?? '');
    if (!is_string($message)) {
        $message = (string)$text;
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($message, 'UTF-8')) {
        $converted = @mb_convert_encoding($message, 'UTF-8', 'Windows-1251');
        if (is_string($converted) && $converted !== '') {
            $message = $converted;
        }
    }
    return preg_replace('/^\xEF\xBB\xBF/u', '', $message);
}

function sendMaxNotify(string $message, string $format = 'markdown'): array
{
    $api = mapGetDirectMaxConfig();
    if ($api['base_url'] === '' || $api['token'] === '' || $api['chat_id'] === '') {
        mapError('MAX notify: API config is not configured', [
            'has_base_url' => $api['base_url'] !== '',
            'has_token' => $api['token'] !== '',
            'has_chat_id' => $api['chat_id'] !== '',
        ]);
        return ['success' => false, 'error' => 'MAX notify API config is not configured'];
    }

    $payload = ['text' => normalizeUtf8Message($message)];
    $normalizedFormat = strtolower(trim($format));
    if ($normalizedFormat === 'markdown' || $normalizedFormat === 'html') {
        $payload['format'] = $normalizedFormat;
    }

    $url = $api['base_url'] . '/messages?chat_id=' . rawurlencode($api['chat_id']);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $api['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = (string)curl_error($ch);

    $ok = ($resp !== false && $httpCode >= 200 && $httpCode < 300);
    if (!$ok) {
        mapError('MAX notify failed', [
            'url' => $url,
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'response' => $resp,
        ]);
        return ['success' => false, 'error' => 'MAX notify request failed'];
    }
    return ['success' => true, 'error' => null];
}
