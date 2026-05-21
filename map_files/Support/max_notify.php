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

function mapAdminTablesReady(PDO $pdo): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    try {
        $required = ['max_settings', 'max_groups', 'max_message_templates', 'max_send_log'];
        foreach ($required as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'");
            if (!$stmt || !$stmt->fetchColumn()) {
                $checked = false;
                return false;
            }
        }
        $checked = true;
        return true;
    } catch (Throwable $e) {
        mapError('MAX admin tables check failed', ['error' => $e->getMessage()]);
        $checked = false;
        return false;
    }
}

function mapAdminLoadSettings(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM max_settings');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $settings = [];
        foreach ((array)$rows as $row) {
            $k = trim((string)($row['setting_key'] ?? ''));
            if ($k !== '') {
                $settings[$k] = (string)($row['setting_value'] ?? '');
            }
        }
        return $settings;
    } catch (Throwable $e) {
        mapError('MAX admin settings load failed', ['error' => $e->getMessage()]);
        return [];
    }
}

function mapAdminRenderTemplate(string $template, array $context): string
{
    if ($template === '') {
        return '';
    }
    return (string)preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function ($m) use ($context) {
        $key = $m[1] ?? '';
        if ($key === '' || !array_key_exists($key, $context)) {
            return '-';
        }
        $value = $context[$key];
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return trim((string)$value) === '' ? '-' : (string)$value;
    }, $template);
}

function mapAdminResolveOverride(PDO $pdo, string $eventKey, string $fallbackMessage): array
{
    if (!mapAdminTablesReady($pdo)) {
        return ['enabled' => true, 'message' => $fallbackMessage, 'chat_id' => null];
    }

    $settings = mapAdminLoadSettings($pdo);
    if (isset($settings['max_enabled']) && (string)$settings['max_enabled'] === '0') {
        return ['enabled' => false, 'message' => $fallbackMessage, 'chat_id' => null];
    }

    $templateMessage = '';
    if ($eventKey !== '') {
        try {
            $stmtTpl = $pdo->prepare('SELECT template_text, is_enabled FROM max_message_templates WHERE event_key = :event_key LIMIT 1');
            if ($stmtTpl && $stmtTpl->execute([':event_key' => $eventKey])) {
                $rowTpl = $stmtTpl->fetch(PDO::FETCH_ASSOC);
                if (is_array($rowTpl)) {
                    if ((int)($rowTpl['is_enabled'] ?? 0) !== 1) {
                        return ['enabled' => false, 'message' => $fallbackMessage, 'chat_id' => null];
                    }
                    $templateMessage = trim((string)($rowTpl['template_text'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            mapError('MAX template resolve failed', ['event_key' => $eventKey, 'error' => $e->getMessage()]);
        }
    }

    $chatId = null;
    try {
        $defaultGroupId = isset($settings['default_group_id']) ? (int)$settings['default_group_id'] : 0;
        if ($defaultGroupId > 0) {
            $stmtGroup = $pdo->prepare('SELECT group_id FROM max_groups WHERE id = :id AND is_active = 1 LIMIT 1');
            if ($stmtGroup && $stmtGroup->execute([':id' => $defaultGroupId])) {
                $chatId = trim((string)$stmtGroup->fetchColumn());
            }
        }
        if ($chatId === null || $chatId === '') {
            $stmtDefault = $pdo->query('SELECT group_id FROM max_groups WHERE is_active = 1 AND is_default = 1 ORDER BY id DESC LIMIT 1');
            $chatId = trim((string)($stmtDefault ? $stmtDefault->fetchColumn() : ''));
        }
    } catch (Throwable $e) {
        mapError('MAX group resolve failed', ['error' => $e->getMessage()]);
        $chatId = null;
    }

    return [
        'enabled' => true,
        'message' => $templateMessage !== '' ? $templateMessage : $fallbackMessage,
        'chat_id' => ($chatId !== '' ? $chatId : null),
    ];
}

function mapAdminWriteLog(PDO $pdo, array $log): void
{
    if (!mapAdminTablesReady($pdo)) {
        return;
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO max_send_log (event_key, group_id, message_text, success, response_text, error_text) VALUES (:event_key, :group_id, :message_text, :success, :response_text, :error_text)');
        if ($stmt) {
            $stmt->execute([
                ':event_key' => (string)($log['event_key'] ?? ''),
                ':group_id' => (string)($log['group_id'] ?? ''),
                ':message_text' => (string)($log['message_text'] ?? ''),
                ':success' => !empty($log['success']) ? 1 : 0,
                ':response_text' => (string)($log['response_text'] ?? ''),
                ':error_text' => (string)($log['error_text'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        mapError('MAX send log failed', ['error' => $e->getMessage()]);
    }
}

function sendMaxNotify(string $message, string $format = 'markdown', array $meta = []): array
{
    $eventKey = trim((string)($meta['event_key'] ?? ''));
    $context = is_array($meta['context'] ?? null) ? $meta['context'] : [];
    if (!array_key_exists('message', $context)) {
        $context['message'] = $message;
    }

    $override = [
        'enabled' => true,
        'message' => $message,
        'chat_id' => null,
    ];
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $override = mapAdminResolveOverride($GLOBALS['pdo'], $eventKey, $message);
    }

    if (!$override['enabled']) {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            mapAdminWriteLog($GLOBALS['pdo'], [
                'event_key' => $eventKey,
                'group_id' => '',
                'message_text' => (string)$message,
                'success' => 1,
                'response_text' => 'Skipped: disabled by admin settings',
                'error_text' => '',
            ]);
        }
        return ['success' => true, 'error' => null, 'skipped' => true];
    }

    $messageToSend = (string)$override['message'];
    if ($messageToSend !== $message) {
        $messageToSend = mapAdminRenderTemplate($messageToSend, $context);
        if (trim($messageToSend) === '' || $messageToSend === '-') {
            $messageToSend = $message;
        }
    }

    $api = mapGetDirectMaxConfig();
    if (!empty($override['chat_id'])) {
        $api['chat_id'] = (string)$override['chat_id'];
    }
    if ($api['base_url'] === '' || $api['token'] === '' || $api['chat_id'] === '') {
        mapError('MAX notify: API config is not configured', [
            'has_base_url' => $api['base_url'] !== '',
            'has_token' => $api['token'] !== '',
            'has_chat_id' => $api['chat_id'] !== '',
        ]);
        return ['success' => false, 'error' => 'MAX notify API config is not configured'];
    }

    $payload = ['text' => normalizeUtf8Message($messageToSend)];
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

        $notifyKey = mapGetNotifyKey();
        if ($notifyKey !== '') {
            $fallbackQuery = http_build_query([
                'key' => $notifyKey,
                'text' => normalizeUtf8Message($messageToSend),
                'format' => $normalizedFormat === 'html' ? 'html' : 'markdown',
            ], '', '&', PHP_QUERY_RFC3986);
            $fallbackUrl = 'http://spugovxsim.temp.swtest.ru/fregat/feo/notify_max.php?' . $fallbackQuery;
            $fallbackResp = @file_get_contents($fallbackUrl);
            if (is_string($fallbackResp) && $fallbackResp !== '') {
                $decoded = json_decode($fallbackResp, true);
                if (is_array($decoded) && !empty($decoded['success'])) {
                    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                        mapAdminWriteLog($GLOBALS['pdo'], [
                            'event_key' => $eventKey,
                            'group_id' => (string)$api['chat_id'],
                            'message_text' => normalizeUtf8Message($messageToSend),
                            'success' => 1,
                            'response_text' => (string)$fallbackResp,
                            'error_text' => '',
                        ]);
                    }
                    return ['success' => true, 'error' => null];
                }
            }
        }

        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            mapAdminWriteLog($GLOBALS['pdo'], [
                'event_key' => $eventKey,
                'group_id' => (string)$api['chat_id'],
                'message_text' => normalizeUtf8Message($messageToSend),
                'success' => 0,
                'response_text' => (string)$resp,
                'error_text' => $curlErr !== '' ? $curlErr : 'MAX notify request failed',
            ]);
        }

        return ['success' => false, 'error' => 'MAX notify request failed'];
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        mapAdminWriteLog($GLOBALS['pdo'], [
            'event_key' => $eventKey,
            'group_id' => (string)$api['chat_id'],
            'message_text' => normalizeUtf8Message($messageToSend),
            'success' => 1,
            'response_text' => (string)$resp,
            'error_text' => '',
        ]);
    }
    return ['success' => true, 'error' => null];
}
