<?php

function mapGetNotifyKey(): string
{
    $configPath = dirname(__DIR__) . '/config/max_notify.php';
    if (is_file($configPath)) {
        $cfg = require $configPath;
        if (is_array($cfg) && !empty($cfg['key'])) {
            $key = trim((string)$cfg['key']);
            if ($key !== '') {
                return $key;
            }
        }
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

function mapBuildNotifyBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseDir = dirname(dirname($scriptName));

    if ($host === '' || $baseDir === '') {
        return '';
    }

    return sprintf('%s://%s%s/notify_max.php', $scheme, $host, $baseDir === '/' ? '' : $baseDir);
}

function sendMaxNotify(string $message): array
{
    $secretKey = mapGetNotifyKey();
    if ($secretKey === '') {
        mapError('MAX notify: key is not configured');
        return ['success' => false, 'error' => 'MAX notify key is not configured'];
    }

    $baseUrl = mapBuildNotifyBaseUrl();
    if ($baseUrl === '') {
        mapError('MAX notify: base URL is not resolved');
        return ['success' => false, 'error' => 'MAX notify base URL is not resolved'];
    }

    $url = $baseUrl
        . '?key=' . rawurlencode($secretKey)
        . '&format=' . rawurlencode('markdown')
        . '&text=' . rawurlencode($message);
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $resp = @file_get_contents($url, false, $context);
    $ok = false;
    if (is_string($resp) && $resp !== '') {
        $data = json_decode($resp, true);
        $ok = is_array($data) && !empty($data['success']);
    }
    if (!$ok) {
        mapError('MAX notify failed', ['url' => $url, 'response' => $resp]);
        return ['success' => false, 'error' => 'MAX notify request failed'];
    }

    return ['success' => true, 'error' => null];
}
