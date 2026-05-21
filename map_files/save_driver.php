<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/max_notify.php';

header('Content-Type: application/json; charset=utf-8');

function driverOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readDriverInput(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function normalizeDriverFullName(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $value);
    $normalized = [];
    foreach ((array)$parts as $part) {
        $part = preg_replace('/[^А-Яа-яЁё-]/u', '', (string)$part);
        if ($part === '') {
            continue;
        }
        $part = mb_strtolower($part, 'UTF-8');
        $first = mb_substr($part, 0, 1, 'UTF-8');
        $rest = mb_substr($part, 1, null, 'UTF-8');
        $normalized[] = mb_strtoupper($first, 'UTF-8') . $rest;
    }
    return trim(implode(' ', $normalized));
}

function normalizeDriverPlate(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $map = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н',
        'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т',
        'X' => 'Х', 'Y' => 'У',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^А-Я0-9]/u', '', $value);
    return mb_substr((string)$value, 0, 9, 'UTF-8');
}

function extractPlateOnly(string $value): string
{
    $value = mb_strtoupper(trim($value), 'UTF-8');
    $map = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н',
        'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т',
        'X' => 'Х', 'Y' => 'У',
    ];
    $value = strtr($value, $map);
    if (preg_match('/([АВЕКМНОРСТУХ]\d{3}[АВЕКМНОРСТУХ]{2}\d{2,3})/u', $value, $m)) {
        return $m[1];
    }
    return trim($value);
}

function isValidDriverName(string $name): bool
{
    return (bool)preg_match('/^[А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+ [А-ЯЁ][а-яё]+$/u', $name);
}

function isValidDriverPlate(string $plate): bool
{
    return (bool)preg_match('/^[А-Я]\d{3}[А-Я]{2}\d{2,3}$/u', $plate);
}

function driverLabel(array $driver): string
{
    $fullName = trim((string)($driver['full_name'] ?? ''));
    $plate = extractPlateOnly((string)($driver['vehicle_make_plate'] ?? ''));
    if ($fullName !== '' && $plate !== '') {
        return $fullName . ' — ' . $plate;
    }
    if ($fullName !== '') {
        return $fullName;
    }
    if ($plate !== '') {
        return $plate;
    }
    $id = (int)($driver['id'] ?? 0);
    return 'Водитель #' . $id;
}

function loadDriversColumns(PDO $pdo): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM drivers');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $columns = [];
    foreach ((array)$rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = $row;
        }
    }
    return $columns;
}

function findExistingDriver(PDO $pdo, string $fullName, string $plate): ?array
{
    $stmt = $pdo->prepare('SELECT id, full_name, vehicle_make_plate FROM drivers WHERE full_name = :full_name AND vehicle_make_plate = :plate LIMIT 1');
    if (!$stmt || !$stmt->execute([':full_name' => $fullName, ':plate' => $plate])) {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function insertDriver(PDO $pdo, array $columns, string $fullName, string $plate, string $gpsType, ?string $trackerUniqueId): array
{
    $fields = [];
    $placeholders = [];
    $params = [];

    if (isset($columns['full_name'])) {
        $fields[] = '`full_name`';
        $placeholders[] = ':full_name';
        $params[':full_name'] = $fullName;
    }
    if (isset($columns['vehicle_make_plate'])) {
        $fields[] = '`vehicle_make_plate`';
        $placeholders[] = ':vehicle_make_plate';
        $params[':vehicle_make_plate'] = $plate;
    }
    if (isset($columns['is_active'])) {
        $fields[] = '`is_active`';
        $placeholders[] = ':is_active';
        $params[':is_active'] = 1;
    }
    if ($trackerUniqueId !== null && $trackerUniqueId !== '') {
        if (isset($columns['tracker_uniqueid'])) {
            $fields[] = '`tracker_uniqueid`';
            $placeholders[] = ':tracker_uniqueid';
            $params[':tracker_uniqueid'] = $trackerUniqueId;
        } elseif (isset($columns['tracker_id'])) {
            $fields[] = '`tracker_id`';
            $placeholders[] = ':tracker_id';
            $params[':tracker_id'] = $trackerUniqueId;
        }
    }
    if (isset($columns['gps_connection_type'])) {
        $fields[] = '`gps_connection_type`';
        $placeholders[] = ':gps_connection_type';
        $params[':gps_connection_type'] = $gpsType;
    }

    if (empty($fields)) {
        throw new RuntimeException('Drivers table does not support required fields');
    }

    $sql = 'INSERT INTO drivers (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        throw new RuntimeException('Failed to insert driver');
    }

    $driverId = (int)$pdo->lastInsertId();
    if ($driverId <= 0) {
        throw new RuntimeException('Failed to resolve created driver id');
    }

    $stmtDriver = $pdo->prepare('SELECT id, full_name, vehicle_make_plate FROM drivers WHERE id = :id LIMIT 1');
    if (!$stmtDriver || !$stmtDriver->execute([':id' => $driverId])) {
        throw new RuntimeException('Failed to fetch created driver');
    }
    $driver = $stmtDriver->fetch(PDO::FETCH_ASSOC);
    if (!is_array($driver)) {
        throw new RuntimeException('Created driver record is empty');
    }

    return $driver;
}

function getSlitexConfig(): array
{
    $baseUrl = trim((string)($GLOBALS['slitexBaseUrl'] ?? getenv('SLITEX_BASE_URL') ?: 'https://slitex.online'));
    $token = trim((string)($GLOBALS['slitexApiToken'] ?? getenv('SLITEX_API_TOKEN') ?: ''));
    return ['base_url' => rtrim($baseUrl, '/'), 'token' => $token];
}

function slitexRequest(string $method, string $url, string $token, ?array $payload = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['success' => false, 'status' => 0, 'error' => 'curl_init failed', 'body' => ''];
    }

    $headers = [
        'Accept: application/json',
        'X-API-Token: ' . $token,
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);

    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['success' => false, 'status' => $status, 'error' => $err !== '' ? $err : 'Request failed', 'body' => ''];
    }

    if ($status < 200 || $status >= 300) {
        return ['success' => false, 'status' => $status, 'error' => 'HTTP ' . $status, 'body' => (string)$resp];
    }

    return ['success' => true, 'status' => $status, 'error' => '', 'body' => (string)$resp];
}

function buildRetranText(string $trackerId): string
{
    $id = trim($trackerId) !== '' ? trim($trackerId) : '[ID ТРЕККЕРА УТОЧНИТЬ]';
    return 'Прошу установить ретрансляцию с треккера ' . $id
        . ' на Wialon 31.207.74.35:5039, так же предоставьте ID для ретрансляции если он не совпадает с ID треккера.'
        . "\nВам должны скинуть ID, его необходимо переслать в общую группу.";
}

function sessionThrottleKey(string $suffix): string
{
    return 'save_driver_throttle_' . $suffix;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $input = readDriverInput();
    $action = trim((string)($input['action'] ?? 'create_driver'));

    if ($action === 'send_retranslation_max') {
        $fullName = normalizeDriverFullName((string)($input['full_name'] ?? ''));
        $plate = normalizeDriverPlate((string)($input['vehicle_make_plate'] ?? ''));
        $trackerId = trim((string)($input['tracker_id'] ?? ''));
        if ($trackerId === '') $trackerId = '[ID ТРЕККЕРА УТОЧНИТЬ]';
        $driverShort = ($plate !== '' ? $plate : 'ТС') . '(' . explode(' ', $fullName)[0] . ')';
        $message = implode("\n", [
            'Ретрансляция для нового водителя:',
            $driverShort,
            'ID трекера: ' . $trackerId,
            'Wialon: 31.207.74.35:5039',
            'Ожидается ID для ретрансляции, если он отличается от ID трекера.',
        ]);
        $notify = sendMaxNotify($message, 'markdown', [
            'event_key' => 'driver_retranslation_requested',
            'context' => [
                'driver' => $driverShort,
                'tracker_id' => $trackerId,
                'wialon' => '31.207.74.35:5039',
            ],
        ]);
        driverOut([
            'success' => true,
            'notify_success' => (bool)($notify['success'] ?? false),
            'notify_error' => $notify['error'] ?? null,
        ]);
    }

    $fullNameRaw = (string)($input['full_name'] ?? '');
    $vehicleNumberRaw = (string)($input['vehicle_make_plate'] ?? $input['vehicle_number'] ?? '');
    $gpsType = trim((string)($input['gps_connection_type'] ?? 'new_tracker'));
    $trackerIdInput = trim((string)($input['tracker_id'] ?? ''));

    $fullName = normalizeDriverFullName($fullNameRaw);
    $plate = normalizeDriverPlate($vehicleNumberRaw);

    if (!isValidDriverName($fullName)) {
        driverOut(['success' => false, 'message' => 'ФИО должно быть в формате: Фамилия Имя Отчество.']);
    }
    if (!isValidDriverPlate($plate)) {
        driverOut(['success' => false, 'message' => 'Госномер должен быть в формате А123АА45 или А123АА456.']);
    }
    if (!in_array($gpsType, ['new_tracker', 'retranslation'], true)) {
        $gpsType = 'new_tracker';
    }

    $throttleKey = sha1($fullName . '|' . $plate . '|' . $gpsType);
    $sessionKey = sessionThrottleKey($throttleKey);
    $lastTs = (int)($_SESSION[$sessionKey] ?? 0);
    if ($lastTs > 0 && (time() - $lastTs) < 4) {
        driverOut(['success' => false, 'message' => 'Подождите пару секунд и повторите попытку.']);
    }
    $_SESSION[$sessionKey] = time();

    $existing = findExistingDriver($pdo, $fullName, $plate);
    if ($existing) {
        $existing['id'] = (int)($existing['id'] ?? 0);
        $existing['label'] = driverLabel($existing);
        driverOut([
            'success' => true,
            'existing' => true,
            'message' => 'Такой водитель уже существует.',
            'driver' => $existing,
        ]);
    }

    $columns = loadDriversColumns($pdo);
    if (!isset($columns['full_name']) || !isset($columns['vehicle_make_plate'])) {
        throw new RuntimeException('drivers table does not contain full_name and vehicle_make_plate');
    }

    $surname = explode(' ', $fullName)[0] ?? '';
    $driverCompact = $plate . '(' . $surname . ')';

    if ($gpsType === 'retranslation') {
        $driver = insertDriver($pdo, $columns, $fullName, $plate, $gpsType, null);
        $driver['id'] = (int)($driver['id'] ?? 0);
        $driver['label'] = driverLabel($driver);
        $copyText = buildRetranText($trackerIdInput);

        driverOut([
            'success' => true,
            'existing' => false,
            'message' => 'Водитель создан.',
            'driver' => $driver,
            'gps_connection_type' => 'retranslation',
            'tracker_id' => $trackerIdInput,
            'copy_text' => $copyText,
            'max_message' => implode("\n", [
                'Ретрансляция для нового водителя:',
                $driverCompact,
                'ID трекера: ' . (trim($trackerIdInput) !== '' ? $trackerIdInput : '[ID ТРЕККЕРА УТОЧНИТЬ]'),
                'Wialon: 31.207.74.35:5039',
                'Ожидается ID для ретрансляции, если он отличается от ID трекера.',
            ]),
        ]);
    }

    $cfg = getSlitexConfig();
    if ($cfg['token'] === '') {
        driverOut(['success' => false, 'message' => 'SLITEX API token не настроен на сервере.']);
    }

    $devicesResp = slitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if (!$devicesResp['success']) {
        driverOut(['success' => false, 'message' => 'Не удалось получить список устройств SLITEX: ' . $devicesResp['error']]);
    }

    $devicesData = json_decode((string)$devicesResp['body'], true);
    if (!is_array($devicesData)) {
        driverOut(['success' => false, 'message' => 'SLITEX вернул некорректный JSON по списку устройств.']);
    }

    $devices = [];
    if (isset($devicesData['data']) && is_array($devicesData['data'])) {
        $devices = $devicesData['data'];
    } elseif (isset($devicesData[0]) && is_array($devicesData[0])) {
        $devices = $devicesData;
    }

    $freeTrackers = [];
    foreach ((array)$devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        $name = trim((string)($device['name'] ?? ''));
        $uniqueid = trim((string)($device['uniqueid'] ?? ''));
        if ($uniqueid !== '' && preg_match('/^\d+$/', $name)) {
            $freeTrackers[] = [
                'uniqueid' => $uniqueid,
                'name' => $name,
            ];
        }
    }

    if ($action === 'check_free_trackers') {
        $first = $freeTrackers[0]['uniqueid'] ?? null;
        driverOut([
            'success' => true,
            'free_count' => count($freeTrackers),
            'first_uniqueid' => $first,
            'free_trackers' => array_slice($freeTrackers, 0, 10),
            'message' => count($freeTrackers) > 0 ? 'Свободные трекеры найдены.' : 'Свободных трекеров нет.',
        ]);
    }

    if (empty($freeTrackers)) {
        driverOut(['success' => false, 'message' => 'Свободных трекеров нет.']);
    }

    $selected = $freeTrackers[0];
    $renamePayload = ['name' => $driverCompact];

    $allowRename = !empty($input['allow_patch_rename']) && (string)$input['allow_patch_rename'] === '1';
    if (!$allowRename) {
        driverOut([
            'success' => false,
            'dry_run' => true,
            'message' => 'Свободный трекер найден: ' . ($selected['uniqueid'] ?? '-') . '. Переименование не выполнено, потому что режим реального изменения SLITEX отключён. Для ручного production-теста включите allow_patch_rename=1.',
            'selected_tracker' => $selected,
            'rename_request' => [
                'method' => 'PATCH',
                'url' => $cfg['base_url'] . '/api/external/devices/' . rawurlencode($selected['uniqueid']) . '/name',
                'payload' => $renamePayload,
            ],
            'free_trackers_count' => count($freeTrackers),
        ]);
    }

    $renameResp = slitexRequest(
        'PATCH',
        $cfg['base_url'] . '/api/external/devices/' . rawurlencode($selected['uniqueid']) . '/name',
        $cfg['token'],
        $renamePayload
    );
    if (!$renameResp['success']) {
        driverOut(['success' => false, 'message' => 'Не удалось переименовать трекер: ' . $renameResp['error']]);
    }

    $driver = insertDriver($pdo, $columns, $fullName, $plate, $gpsType, $selected['uniqueid']);
    $driver['id'] = (int)($driver['id'] ?? 0);
    $driver['label'] = driverLabel($driver);

    $maxMessage = implode("\n", [
        'Настройки для нового водителя:',
        $driverCompact,
        'Для водителя: ' . $selected['uniqueid'],
        'Для ФЭО: outID outIP:outPort outProtocol',
    ]);

    $notify = sendMaxNotify($maxMessage, 'markdown', [
        'event_key' => 'driver_new_tracker_configured',
        'context' => [
            'driver' => $driverCompact,
            'tracker_uniqueid' => $selected['uniqueid'],
            'feo_params' => 'outID outIP:outPort outProtocol',
        ],
    ]);

    driverOut([
        'success' => true,
        'existing' => false,
        'message' => 'Трекер настроен.',
        'driver' => $driver,
        'gps_connection_type' => 'new_tracker',
        'tracker_uniqueid' => $selected['uniqueid'],
        'tracker_name' => $driverCompact,
        'free_trackers_remaining' => max(0, count($freeTrackers) - 1),
        'notify_success' => (bool)($notify['success'] ?? false),
        'notify_error' => $notify['error'] ?? null,
    ]);
} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('save_driver failed', ['error' => $e->getMessage()]);
    }
    driverOut([
        'success' => false,
        'message' => 'Ошибка создания водителя.',
    ]);
}
