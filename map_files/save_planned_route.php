<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

const STATUS_PLANNED = 'planned_route';
const STATUS_FOUND = 'found';
const STATUS_STARTED = 'started';

function jsonOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeIdsString($raw): array
{
    $parts = is_array($raw) ? $raw : explode(',', (string)$raw);
    $seen = [];
    $normalized = [];
    foreach ($parts as $part) {
        $id = trim((string)$part);
        if ($id === '' || !preg_match('/^\d+$/', $id)) {
            continue;
        }
        if (!isset($seen[$id])) {
            $seen[$id] = true;
            $normalized[] = $id;
        }
    }
    return $normalized;
}

function formatTons($tons): string
{
    return number_format((float)$tons, 3, '.', ' ');
}

function formatDateRu($value): string
{
    $v = trim((string)$value);
    if ($v === '') {
        return 'не указано';
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return $v;
    }
    return date('d.m.Y H:i', $ts);
}

function getMaxNotifyKey(): string
{
    global $maxNotifyKey;
    if (!empty($maxNotifyKey)) {
        return (string)$maxNotifyKey;
    }
    $envKey = getenv('MAX_NOTIFY_KEY');
    if (is_string($envKey) && trim($envKey) !== '') return trim($envKey);
    if (!empty($_SERVER['MAX_NOTIFY_KEY'])) return (string)$_SERVER['MAX_NOTIFY_KEY'];
    if (!empty($_ENV['MAX_NOTIFY_KEY'])) return (string)$_ENV['MAX_NOTIFY_KEY'];
    return '';
}

function sendMaxNotification($text): array
{
    $secretKey = getMaxNotifyKey();
    if ($secretKey === '') {
        mapError('MAX notify: key is not configured');
        return ['success' => false, 'error' => 'MAX notify key is not configured'];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseDir = dirname(dirname($scriptName));
    if ($host === '' || $baseDir === '') {
        mapError('MAX notify: host/baseDir not resolved', ['host' => $host, 'script' => $scriptName]);
        return ['success' => false, 'error' => 'MAX notify base URL is not resolved'];
    }
    $url = sprintf('%s://%s%s/notify_max.php?key=%s&text=%s',
        $scheme,
        $host,
        $baseDir === '/' ? '' : $baseDir,
        rawurlencode($secretKey),
        rawurlencode((string)$text)
    );

    $ok = false;
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $resp = @file_get_contents($url, false, $context);
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

function getDriverLabelById(PDO $pdo, $driverId): string
{
    $driverId = (int)$driverId;
    if ($driverId <= 0) {
        return 'Водитель не указан';
    }
    try {
        $stmt = $pdo->prepare('SELECT full_name, vehicle_make_plate FROM drivers WHERE id = ? LIMIT 1');
        if (!$stmt || !$stmt->execute([$driverId])) {
            return 'Водитель не указан';
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'Водитель не указан';
        }
        $fullName = trim((string)($row['full_name'] ?? ''));
        $plate = trim((string)($row['vehicle_make_plate'] ?? ''));
        if ($fullName === '' && $plate === '') {
            return 'Водитель не указан';
        }
        return trim(($fullName !== '' ? $fullName : 'Водитель') . ' / ' . ($plate !== '' ? $plate : 'без номера'));
    } catch (Throwable $e) {
        mapError('Driver label load failed', ['error' => $e->getMessage()]);
        return 'Водитель не указан';
    }
}

function getRouteMetrics(PDO $pdo, array $idList): array
{
    if (empty($idList)) {
        return ['count' => 0, 'sum_tons' => 0.0];
    }
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(mass_netto), 0) AS sum_tons FROM feo WHERE zayavka_id IN ({$placeholders})");
        if (!$stmt || !$stmt->execute($idList)) {
            return ['count' => 0, 'sum_tons' => 0.0];
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'count' => isset($row['cnt']) ? (int)$row['cnt'] : 0,
            'sum_tons' => isset($row['sum_tons']) ? (float)$row['sum_tons'] : 0.0,
        ];
    } catch (Throwable $e) {
        mapError('Route metrics failed', ['error' => $e->getMessage()]);
        return ['count' => 0, 'sum_tons' => 0.0];
    }
}

function loadFlightSnapshot(PDO $pdo, $flightId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT id, status, driver_id, zayavki_ids, planned_start_date_from, planned_start_date_to, cost FROM flights WHERE id = ? LIMIT 1');
        if (!$stmt || !$stmt->execute([(int)$flightId])) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $ids = normalizeIdsString($row['zayavki_ids'] ?? '');
        $metrics = getRouteMetrics($pdo, $ids);
        $row['_ids'] = $ids;
        $row['_count'] = $metrics['count'];
        $row['_sum_tons'] = $metrics['sum_tons'];
        $row['_driver_label'] = getDriverLabelById($pdo, $row['driver_id'] ?? 0);
        return $row;
    } catch (Throwable $e) {
        mapError('Load flight snapshot failed', ['flight_id' => $flightId, 'error' => $e->getMessage()]);
        return null;
    }
}

function assertTransitionAllowed(array $flight, string $targetStatus): ?string
{
    $current = (string)($flight['status'] ?? '');
    if ($current === STATUS_PLANNED && $targetStatus === STATUS_FOUND) return null;
    if ($current === STATUS_FOUND && $targetStatus === STATUS_STARTED) return null;
    if ($current === STATUS_FOUND && $targetStatus === STATUS_PLANNED) return null;
    if ($current === STATUS_STARTED && $targetStatus === STATUS_FOUND) return null;
    return 'Недопустимый переход статуса';
}

function resolveUsersRoleColumn(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $map = [];
        foreach ((array)$columns as $column) {
            $map[(string)$column] = true;
        }
        foreach (['role', 'user_role', 'type'] as $candidate) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }
    } catch (Throwable $e) {
        mapError('resolveUsersRoleColumn failed', ['error' => $e->getMessage()]);
    }
    return null;
}

function resolveValidLogistManagerId(PDO $pdo, $managerIdRaw): int
{
    $managerId = (int)$managerIdRaw;
    if ($managerId <= 0) {
        return 0;
    }

    $roleColumn = resolveUsersRoleColumn($pdo);
    if ($roleColumn === null) {
        return 0;
    }

    try {
        $sql = "SELECT id FROM users WHERE id = :id AND LOWER(TRIM($roleColumn)) = 'logist' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute([':id' => $managerId])) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int)($row['id'] ?? 0) : 0;
    } catch (Throwable $e) {
        mapError('resolveValidLogistManagerId failed', ['error' => $e->getMessage(), 'manager_id' => $managerId]);
        return 0;
    }
}

function validateRouteData(PDO $pdo, array $data, bool $requireFullForFoundTransition): array
{
    $ids = normalizeIdsString($data['zayavki_ids'] ?? '');
    if (empty($ids)) {
        return [false, 'Список заявок пустой', [], ['zayavki_ids' => 'Список заявок пустой']];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtIds = $pdo->prepare("SELECT zayavka_id FROM feo WHERE zayavka_id IN ({$placeholders})");
    if (!$stmtIds || !$stmtIds->execute($ids)) {
        return [false, 'Не удалось проверить заявки', [], ['zayavki_ids' => 'Не удалось проверить заявки']];
    }
    $existsRows = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
    $existsMap = [];
    if (is_array($existsRows)) {
        foreach ($existsRows as $v) {
            $existsMap[(string)$v] = true;
        }
    }
    foreach ($ids as $id) {
        if (!isset($existsMap[$id])) {
            return [false, "Заявка {$id} не существует", [], ['zayavki_ids' => "Заявка {$id} не существует"]];
        }
    }

    $driverId = (int)($data['driver_id'] ?? 0);
    $plannedFromRaw = trim((string)($data['planned_start_date_from'] ?? ''));
    $plannedToRaw = trim((string)($data['planned_start_date_to'] ?? ''));
    $costRaw = $data['cost'] ?? null;

    $plannedFrom = null;
    $plannedTo = null;
    if ($plannedFromRaw !== '') {
        $ts = strtotime($plannedFromRaw);
        if ($ts === false) return [false, 'Некорректная дата "С"', [], ['planned_start_date_from' => 'Некорректная дата "С"']];
        $plannedFrom = date('Y-m-d H:i:s', $ts);
    }
    if ($plannedToRaw !== '') {
        $ts = strtotime($plannedToRaw);
        if ($ts === false) return [false, 'Некорректная дата "По"', [], ['planned_start_date_to' => 'Некорректная дата "По"']];
        $plannedTo = date('Y-m-d H:i:s', $ts);
    }
    if ($plannedFrom !== null && $plannedTo !== null && strtotime($plannedFrom) > strtotime($plannedTo)) {
        return [false, 'Дата "С" не может быть позже "По"', [], [
            'planned_start_date_from' => 'Дата "С" не может быть позже "По"',
            'planned_start_date_to' => 'Дата "С" не может быть позже "По"'
        ]];
    }

    $cost = null;
    if ($costRaw !== null && $costRaw !== '') {
        if (!is_numeric($costRaw)) return [false, 'Стоимость должна быть числом', [], ['cost' => 'Стоимость должна быть числом']];
        $cost = (float)$costRaw;
    }

    $driverOk = false;
    if ($driverId > 0) {
        $stmtDriver = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        if ($stmtDriver && $stmtDriver->execute([$driverId])) {
            $driverOk = (bool)$stmtDriver->fetch(PDO::FETCH_ASSOC);
        }
    }
    if (!$driverOk && $requireFullForFoundTransition) return [false, 'Не выбран корректный водитель', [], ['driver_id' => 'Не выбран корректный водитель']];
    if ($requireFullForFoundTransition && ($plannedFrom === null || $plannedTo === null)) {
        return [false, 'Для перевода в "Исполнит. найден" обязательны обе даты', [], [
            'planned_start_date_from' => 'Обязательная дата',
            'planned_start_date_to' => 'Обязательная дата'
        ]];
    }
    if ($requireFullForFoundTransition && $cost === null) return [false, 'Для перевода в "Исполнит. найден" обязательна стоимость', [], ['cost' => 'Обязательная стоимость']];

    return [true, '', [
        'zayavki_ids_canonical' => implode(',', $ids),
        'zayavki_count' => count($ids),
        'driver_id' => $driverId > 0 ? $driverId : null,
        'planned_start_date_from' => $plannedFrom,
        'planned_start_date_to' => $plannedTo,
        'cost' => $cost,
    ], []];
}

function buildFoundDiffMessage(array $before, array $after, int $flightId): string
{
    $changes = [];
    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $changes[] = "Водитель:\n{$before['_driver_label']}\n→\n{$after['_driver_label']}";
    }
    if ((string)$before['planned_start_date_from'] !== (string)$after['planned_start_date_from']) {
        $changes[] = "Дата начала:\n" . formatDateRu($before['planned_start_date_from']) . "\n→\n" . formatDateRu($after['planned_start_date_from']);
    }
    if ((string)$before['planned_start_date_to'] !== (string)$after['planned_start_date_to']) {
        $changes[] = "Дата окончания:\n" . formatDateRu($before['planned_start_date_to']) . "\n→\n" . formatDateRu($after['planned_start_date_to']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        $changes[] = "Список заявок:\n{$before['zayavki_ids']}\n→\n{$after['zayavki_ids']}";
    }
    if ((int)$before['_count'] !== (int)$after['_count']) {
        $changes[] = "Количество заявок:\n{$before['_count']}\n→\n{$after['_count']}";
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $changes[] = "Общая масса:\n" . formatTons($before['_sum_tons']) . " т\n→\n" . formatTons($after['_sum_tons']) . " т";
    }
    if ((string)$before['cost'] !== (string)$after['cost']) {
        $changes[] = "Стоимость:\n" . ($before['cost'] === null ? 'не указана' : $before['cost']) . "\n→\n" . ($after['cost'] === null ? 'не указана' : $after['cost']);
    }

    if (empty($changes)) {
        return '';
    }

    return "Рейс #{$flightId} изменён в статусе ИСПОЛНИТЕЛЬНАЙДЕН\n\nИзменения:\n\n" . implode("\n\n", $changes);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'Неверный метод']);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Неверный JSON');
    }

    $action = trim((string)($data['action'] ?? 'save'));
    $routeId = isset($data['id']) ? (int)$data['id'] : 0;
    $name = trim((string)($data['name'] ?? ''));

    if ($action === 'save') {
        $requireFull = false;
        if ($routeId > 0) {
            $current = loadFlightSnapshot($pdo, $routeId);
            $requireFull = is_array($current) && (string)$current['status'] === STATUS_FOUND;
        }
        [$ok, $msg, $normalized, $errors] = validateRouteData($pdo, $data, $requireFull);
        if (!$ok) {
            jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
        }

        if ($routeId > 0) {
            $before = loadFlightSnapshot($pdo, $routeId);
            if (!$before) jsonOut(['success' => false, 'message' => 'Рейс не найден']);

            $stmt = $pdo->prepare("
                UPDATE flights
                SET comment = :comment,
                    cost = :cost,
                    zayavki_ids = :zayavki_ids,
                    zayavki_count = :count,
                    planned_start_date_from = :planned_from,
                    planned_start_date_to = :planned_to,
                    driver_id = :driver_id,
                    block_date = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([
                ':comment' => $name !== '' ? $name : ($before['comment'] ?? ('Рейс #' . $routeId)),
                ':cost' => $normalized['cost'],
                ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
                ':count' => $normalized['zayavki_count'],
                ':planned_from' => $normalized['planned_start_date_from'],
                ':planned_to' => $normalized['planned_start_date_to'],
                ':driver_id' => $normalized['driver_id'],
                ':id' => $routeId,
            ]);

            $after = loadFlightSnapshot($pdo, $routeId);
            $notifyResult = ['success' => true, 'error' => null];
            if ($after && (string)($before['status'] ?? '') === STATUS_FOUND) {
                $text = buildFoundDiffMessage($before, $after, $routeId);
                if ($text !== '') {
                    $notifyResult = sendMaxNotification($text);
                }
            }

            jsonOut([
                'success' => true,
                'message' => 'Рейс обновлён',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        $assignedManagerId = resolveValidLogistManagerId($pdo, $data['assigned_manager_id'] ?? 0);
        if ($assignedManagerId <= 0) {
            jsonOut([
                'success' => false,
                'message' => 'Выберите менеджера для планируемого рейса.',
                'errors' => [
                    'assigned_manager_id' => 'Выберите менеджера для планируемого рейса.'
                ]
            ]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO flights (status, comment, cost, zayavki_ids, zayavki_count, assigned_manager_id, planned_start_date_from, planned_start_date_to, driver_id, block_date)
            VALUES (:status, :comment, :cost, :zayavki_ids, :count, :assigned_manager_id, :planned_from, :planned_to, :driver_id, NOW())
        ");
        $stmt->execute([
            ':status' => STATUS_PLANNED,
            ':comment' => $name !== '' ? $name : 'Новый рейс',
            ':cost' => $normalized['cost'],
            ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
            ':count' => $normalized['zayavki_count'],
            ':assigned_manager_id' => $assignedManagerId,
            ':planned_from' => $normalized['planned_start_date_from'],
            ':planned_to' => $normalized['planned_start_date_to'],
            ':driver_id' => $normalized['driver_id'],
        ]);
        jsonOut(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Маршрут создан']);
    }

    if ($routeId <= 0) {
        jsonOut(['success' => false, 'message' => 'Некорректный ID рейса']);
    }
    $flight = loadFlightSnapshot($pdo, $routeId);
    if (!$flight) {
        jsonOut(['success' => false, 'message' => 'Рейс не найден']);
    }

    if ($action === 'delete_route') {
        if ((string)$flight['status'] !== STATUS_PLANNED) {
            jsonOut(['success' => false, 'message' => 'Удаление доступно только для ПЛАНИРУЕМЫЙ']);
        }
        $stmt = $pdo->prepare('DELETE FROM flights WHERE id = :id AND status = :status');
        $stmt->execute([':id' => $routeId, ':status' => STATUS_PLANNED]);
        jsonOut(['success' => true, 'message' => 'Маршрут удален']);
    }

    if ($action === 'transition') {
        $target = trim((string)($data['target_status'] ?? ''));
        if (!in_array($target, [STATUS_PLANNED, STATUS_FOUND, STATUS_STARTED], true)) {
            jsonOut(['success' => false, 'message' => 'Некорректный целевой статус']);
        }
        $deny = assertTransitionAllowed($flight, $target);
        if ($deny !== null) {
            jsonOut(['success' => false, 'message' => $deny]);
        }

        if ($target === STATUS_FOUND) {
            [$ok, $msg, $normalized, $errors] = validateRouteData($pdo, $data, true);
            if (!$ok) {
                jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
            }
            $comment = trim((string)($data['name'] ?? ''));
            $stmtApply = $pdo->prepare("
                UPDATE flights
                SET comment = :comment,
                    cost = :cost,
                    zayavki_ids = :zayavki_ids,
                    zayavki_count = :count,
                    planned_start_date_from = :planned_from,
                    planned_start_date_to = :planned_to,
                    driver_id = :driver_id,
                    block_date = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $stmtApply->execute([
                ':comment' => $comment !== '' ? $comment : ($flight['comment'] ?? ('Рейс #' . $routeId)),
                ':cost' => $normalized['cost'],
                ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
                ':count' => $normalized['zayavki_count'],
                ':planned_from' => $normalized['planned_start_date_from'],
                ':planned_to' => $normalized['planned_start_date_to'],
                ':driver_id' => $normalized['driver_id'],
                ':id' => $routeId,
            ]);
            $flight = loadFlightSnapshot($pdo, $routeId) ?: $flight;
        }

        if ($target === STATUS_STARTED) {
            $actualRaw = trim((string)($data['actual_start_date'] ?? ''));
            $actualTs = $actualRaw !== '' ? strtotime($actualRaw) : time();
            if ($actualTs === false) {
                jsonOut(['success' => false, 'message' => 'Некорректная дата начала вывоза']);
            }
            $stmt = $pdo->prepare('UPDATE flights SET status = :status, actual_start_date = :actual_start WHERE id = :id LIMIT 1');
            $stmt->execute([
                ':status' => STATUS_STARTED,
                ':actual_start' => date('Y-m-d H:i:s', $actualTs),
                ':id' => $routeId,
            ]);
            $notifyResult = sendMaxNotification("Рейс #{$routeId} переведен в статус ВЫВОЗНАЧАЛСЯ\n\nПодключается мониторинг выполнения перевозки и логика трекера.");
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ВЫВОЗНАЧАЛСЯ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        $stmt = $pdo->prepare('UPDATE flights SET status = :status WHERE id = :id LIMIT 1');
        $stmt->execute([':status' => $target, ':id' => $routeId]);
        $after = loadFlightSnapshot($pdo, $routeId) ?: $flight;

        if ($target === STATUS_FOUND) {
            $message = "Рейс #{$routeId} переведен в статус ИСПОЛНИТЕЛЬНАЙДЕН\n\n" .
                "Заявок: {$after['_count']}\n" .
                "Общая масса: " . formatTons($after['_sum_tons']) . " т\n\n" .
                "Водитель:\n{$after['_driver_label']}\n\n" .
                "Даты:\n" . formatDateRu($after['planned_start_date_from']) . " — " . formatDateRu($after['planned_start_date_to']) . "\n\n" .
                "Стоимость:\n" . ($after['cost'] === null ? 'не указана' : $after['cost']) . "\n\n" .
                "Начинаем подготовку транспортных документов на указанные даты и указанного водителя.";
            $notifyResult = sendMaxNotification($message);
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ИСПОЛНИТЕЛЬНАЙДЕН',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_PLANNED) {
            $notifyResult = sendMaxNotification("Рейс #{$routeId} возвращён в статус ПЛАНИРУЕМЫЙ\n\nПодготовку документов необходимо проверить/приостановить.");
            jsonOut([
                'success' => true,
                'message' => 'Рейс возвращен в ПЛАНИРУЕМЫЙ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }
    }

    jsonOut(['success' => false, 'message' => 'Неизвестное действие']);
} catch (Throwable $e) {
    mapError('save_planned_route fatal', ['error' => $e->getMessage()]);
    jsonOut(['success' => false, 'message' => 'Внутренняя ошибка']);
}
