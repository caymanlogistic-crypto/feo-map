<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Support/max_notify.php';
header('Content-Type: application/json; charset=utf-8');

const STATUS_PLANNED = 'planned_route';
const STATUS_FOUND = 'found';
const STATUS_STARTED = 'started';
const STATUS_COMPLETED = 'completed';

function jsonOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function quoteIdent(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
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

function sendMaxNotification($text): array
{
    return sendMaxNotify((string)$text);
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
        $stmt = $pdo->prepare('SELECT id, status, driver_id, zayavki_ids, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date, comment, cost, unload_type, assigned_manager_id FROM flights WHERE id = ? LIMIT 1');
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
    if ($current === STATUS_STARTED && $targetStatus === STATUS_COMPLETED) return null;
    return 'Недопустимый переход статуса';
}

function normalizeDateToDb($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        throw new InvalidArgumentException('Некорректная дата');
    }
    return date('Y-m-d H:i:s', $ts);
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
        foreach (['Роль', 'role', 'user_role', 'type'] as $candidate) {
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
        $sql = "SELECT id FROM users WHERE id = :id AND LOWER(TRIM(" . quoteIdent($roleColumn) . ")) = 'logist' LIMIT 1";
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

function resolveFlightsManagerColumn(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM flights');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $map = [];
        foreach ((array)$columns as $column) {
            $map[(string)$column] = true;
        }
        foreach (['assigned_manager_id', 'manager_id'] as $candidate) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }
    } catch (Throwable $e) {
        mapError('resolveFlightsManagerColumn failed', ['error' => $e->getMessage()]);
    }
    return null;
}

function validateRouteData(PDO $pdo, array $data, bool $requireFullForFoundTransition, bool $requireTitle = false): array
{
    if ($requireTitle) {
        $title = trim((string)($data['comment'] ?? $data['name'] ?? ''));
        if ($title === '') {
            return [false, 'Укажите комментарий / заголовок рейса', [], ['comment' => 'Обязательный комментарий / заголовок']];
        }
    }

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
    $unloadTypeRaw = strtoupper(trim((string)($data['unload_type'] ?? 'OO')));
    $unloadType = $unloadTypeRaw === 'SKLAD' ? 'SKLAD' : 'OO';

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
        'unload_type' => $unloadType,
    ], []];
}

function formatUnloadTypeRu(?string $type): string
{
    return strtoupper(trim((string)$type)) === 'SKLAD' ? 'СКЛАД' : 'ОО';
}

function formatDateShortRu($value): string
{
    $v = trim((string)$value);
    if ($v === '') return 'не указано';
    $ts = strtotime($v);
    return $ts === false ? $v : date('d.m', $ts);
}

function formatDateRangeShortRu($from, $to): string
{
    $fromShort = formatDateShortRu($from);
    $toShort = formatDateShortRu($to);
    if ($fromShort === 'не указано' && $toShort === 'не указано') return 'не указано';
    if ($fromShort === 'не указано') return $toShort;
    if ($toShort === 'не указано') return $fromShort;
    if ($fromShort === $toShort) return $fromShort;
    return $fromShort . '–' . $toShort;
}

function formatMoneyRu($value): string
{
    if ($value === null || $value === '') return '0 ₽';
    $num = (float)$value;
    return number_format($num, 0, '.', ' ') . ' ₽';
}

function formatKgFromTons($tons): string
{
    return number_format((int)round((float)$tons * 1000), 0, '.', ' ') . ' кг';
}

function compactDriverLabel(string $label): string
{
    $v = trim($label);
    if ($v === '') return 'Водитель не указан';
    if (preg_match('/([А-ЯЁA-Z]\d{3}[А-ЯЁA-Z]{2}\d{2,3})/u', $v, $mPlate)) {
        $plate = trim($mPlate[1]);
        if (preg_match('/\(([^)]+)\)/u', $v, $mName)) {
            $surname = trim((string)explode(' ', trim($mName[1]))[0]);
            if ($surname !== '') return $plate . '(' . $surname . ')';
        }
        return $plate;
    }
    return $v;
}

function getManagerDisplayNameById(PDO $pdo, $managerId): string
{
    $id = (int)$managerId;
    if ($id <= 0) return 'Менеджер не указан';
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM users');
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $map = [];
        foreach ((array)$columns as $column) $map[(string)$column] = true;

        $parts = [];
        if (isset($map['Фамилия'])) $parts[] = "COALESCE(u.`Фамилия`, '')";
        if (isset($map['Имя'])) $parts[] = "COALESCE(u.`Имя`, '')";
        if (isset($map['full_name'])) $parts[] = "COALESCE(u.full_name, '')";
        if (isset($map['name'])) $parts[] = "COALESCE(u.name, '')";
        if (isset($map['username'])) $parts[] = "COALESCE(u.username, '')";
        if (isset($map['login'])) $parts[] = "COALESCE(u.login, '')";
        if (empty($parts)) return 'Менеджер #' . $id;

        $sql = 'SELECT ' . implode(", ' ', ", $parts) . ' AS manager_name FROM users u WHERE u.id = :id LIMIT 1';
        $q = $pdo->prepare($sql);
        if (!$q || !$q->execute([':id' => $id])) return 'Менеджер #' . $id;
        $row = $q->fetch(PDO::FETCH_ASSOC);
        $name = trim(preg_replace('/\s+/u', ' ', (string)($row['manager_name'] ?? '')));
        return $name !== '' ? $name : 'Менеджер #' . $id;
    } catch (Throwable $e) {
        mapError('Manager name load failed', ['manager_id' => $id, 'error' => $e->getMessage()]);
        return 'Менеджер #' . $id;
    }
}

function buildRouteTitle(array $flight, int $flightId): string
{
    $title = trim((string)($flight['comment'] ?? ''));
    return $title !== '' ? $title : ('Рейс #' . $flightId);
}

function buildCompactMetaLine(array $flight): string
{
    $count = (int)($flight['_count'] ?? 0);
    $kg = formatKgFromTons((float)($flight['_sum_tons'] ?? 0));
    $meta = "{$count} заяв. • {$kg}";
    if (strtoupper(trim((string)($flight['unload_type'] ?? 'OO'))) === 'SKLAD') {
        $meta .= " • СКЛАД";
    }
    return $meta;
}

function buildCompactFlightContext(PDO $pdo, array $flight, int $flightId): array
{
    $title = buildRouteTitle($flight, $flightId);
    $manager = getManagerDisplayNameById($pdo, $flight['assigned_manager_id'] ?? 0);
    $driver = compactDriverLabel((string)($flight['_driver_label'] ?? ''));
    $meta = buildCompactMetaLine($flight);
    return [$title, $manager, $driver, $meta];
}

function buildPlannedDateRangeUpdateMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    $beforeFrom = trim((string)($before['planned_start_date_from'] ?? ''));
    $beforeTo = trim((string)($before['planned_start_date_to'] ?? ''));
    $afterFrom = trim((string)($after['planned_start_date_from'] ?? ''));
    $afterTo = trim((string)($after['planned_start_date_to'] ?? ''));

    $hadBefore = ($beforeFrom !== '' || $beforeTo !== '');
    $hasAfter = ($afterFrom !== '' || $afterTo !== '');
    if (!$hasAfter) return '';
    if ($beforeFrom === $afterFrom && $beforeTo === $afterTo) return '';

    $title = buildRouteTitle($after, $flightId);
    $driver = compactDriverLabel((string)($after['_driver_label'] ?? ''));
    $manager = getManagerDisplayNameById($pdo, $after['assigned_manager_id'] ?? 0);
    $meta = buildCompactMetaLine($after);
    $rangeAfter = formatDateRangeShortRu($afterFrom, $afterTo);

    if (!$hadBefore) {
        return "В плановый рейс добавлена предварительная дата начала вывоза\n" .
            "#{$flightId} {$title}\n" .
            "Начало вывоза: {$rangeAfter}\n" .
            "{$meta}\n" .
            "{$driver}\n" .
            "> Сообщаемые даты носят ознакомительный характер и могут быть изменены";
    }

    $rangeBefore = formatDateRangeShortRu($beforeFrom, $beforeTo);
    return "В плановом маршруте изменены предварительные даты вывоза\n" .
        "#{$flightId} {$title}\n" .
        "Было: {$rangeBefore}\n" .
        "Стало: {$rangeAfter}\n" .
        "Рейс закреплен: {$manager}\n" .
        "> Обновленные даты также ознакомительные и могут быть изменены";
}

function buildPlannedToFoundMessage(PDO $pdo, array $after, int $flightId): string
{
    [$title, $manager, $driver, $meta] = buildCompactFlightContext($pdo, $after, $flightId);
    $dateRange = formatDateRangeShortRu($after['planned_start_date_from'] ?? '', $after['planned_start_date_to'] ?? '');
    return "РЕЙС СФОРМИРОВАН\n" .
        "#{$flightId} {$title}\n" .
        "Начало вывоза: {$dateRange}\n" .
        "{$meta}\n" .
        "{$driver}\n" .
        "Рейс закреплен: {$manager}\n" .
        "> Просим подготовить товаросопроводительные документы на заявленные дату и водителя";
}

function buildFoundDiffMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    [$title, $manager] = buildCompactFlightContext($pdo, $after, $flightId);
    $changes = [];
    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $changes[] = 'Водитель: ' . compactDriverLabel((string)$before['_driver_label']) . ' → ' . compactDriverLabel((string)$after['_driver_label']);
    }
    if ((string)$before['planned_start_date_from'] !== (string)$after['planned_start_date_from']) {
        $changes[] = 'Даты: ' . formatDateShortRu($before['planned_start_date_from']) . '-' . formatDateShortRu($before['planned_start_date_to'])
            . ' → ' . formatDateShortRu($after['planned_start_date_from']) . '-' . formatDateShortRu($after['planned_start_date_to']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        $changes[] = 'Заявки: ' . (int)$before['_count'] . ' → ' . (int)$after['_count'];
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $changes[] = 'Вес: ' . formatKgFromTons((float)$before['_sum_tons']) . ' → ' . formatKgFromTons((float)$after['_sum_tons']);
    }

    if ((string)($before['unload_type'] ?? 'OO') !== (string)($after['unload_type'] ?? 'OO')) {
        $changes[] = 'Тип выгрузки: ' . formatUnloadTypeRu($before['unload_type'] ?? 'OO') . ' → ' . formatUnloadTypeRu($after['unload_type'] ?? 'OO');
    }
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) {
        $changes[] = 'Название: ' . buildRouteTitle($before, $flightId) . ' → ' . buildRouteTitle($after, $flightId);
    }

    if (empty($changes)) {
        return '';
    }

    return "Изменён рейс #{$flightId} в «Исполнит. найден»\n{$title} | {$manager}\n" . implode("\n", $changes);
}


function buildStartedDiffMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    [$title, $manager] = buildCompactFlightContext($pdo, $after, $flightId);
    $changes = [];
    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $changes[] = 'Водитель: ' . compactDriverLabel((string)$before['_driver_label']) . ' → ' . compactDriverLabel((string)$after['_driver_label']);
    }
    if ((string)$before['actual_start_date'] !== (string)$after['actual_start_date']) {
        $changes[] = 'Старт: ' . formatDateShortRu($before['actual_start_date']) . ' → ' . formatDateShortRu($after['actual_start_date']);
    }
    if ((string)$before['actual_end_date'] !== (string)$after['actual_end_date']) {
        $changes[] = 'Финиш: ' . formatDateShortRu($before['actual_end_date']) . ' → ' . formatDateShortRu($after['actual_end_date']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        $changes[] = 'Заявки: ' . (int)$before['_count'] . ' → ' . (int)$after['_count'];
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $changes[] = 'Вес: ' . formatKgFromTons((float)$before['_sum_tons']) . ' → ' . formatKgFromTons((float)$after['_sum_tons']);
    }
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) {
        $changes[] = 'Название: ' . buildRouteTitle($before, $flightId) . ' → ' . buildRouteTitle($after, $flightId);
    }
    if ((string)($before['unload_type'] ?? 'OO') !== (string)($after['unload_type'] ?? 'OO')) {
        $changes[] = 'Тип выгрузки: ' . formatUnloadTypeRu($before['unload_type'] ?? 'OO') . ' → ' . formatUnloadTypeRu($after['unload_type'] ?? 'OO');
    }

    if (empty($changes)) {
        return '';
    }
    return "Изменён рейс #{$flightId} во время выполнения\n{$title} | {$manager}\n" . implode("\n", $changes) . "\nРейс находится в выполнении. Проверьте корректность изменений.";
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
            [$ok, $msg, $normalized, $errors] = validateRouteData($pdo, $data, $requireFull, true);
        if (!$ok) {
            jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
        }

        if ($routeId > 0) {
            $before = loadFlightSnapshot($pdo, $routeId);
            if (!$before) jsonOut(['success' => false, 'message' => 'Рейс не найден']);

            $statusBefore = (string)($before['status'] ?? '');
            $actualStartValue = $before['actual_start_date'] ?? null;
            $actualEndValue = $before['actual_end_date'] ?? null;
            if ($statusBefore === STATUS_STARTED) {
                try {
                    if (array_key_exists('actual_start_date', $data)) {
                        $actualStartValue = normalizeDateToDb($data['actual_start_date']);
                    }
                    if (array_key_exists('actual_end_date', $data)) {
                        $actualEndValue = normalizeDateToDb($data['actual_end_date']);
                    }
                } catch (Throwable $e) {
                    jsonOut(['success' => false, 'message' => 'Некорректные фактические даты']);
                }
            }

            $stmt = $pdo->prepare("
                UPDATE flights
                SET comment = :comment,
                    cost = :cost,
                    zayavki_ids = :zayavki_ids,
                    zayavki_count = :count,
                    planned_start_date_from = :planned_from,
                    planned_start_date_to = :planned_to,
                    actual_start_date = :actual_start_date,
                    actual_end_date = :actual_end_date,
                    unload_type = :unload_type,
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
                ':actual_start_date' => $actualStartValue,
                ':actual_end_date' => $actualEndValue,
                ':unload_type' => $normalized['unload_type'],
                ':driver_id' => $normalized['driver_id'],
                ':id' => $routeId,
            ]);

            $after = loadFlightSnapshot($pdo, $routeId);
            $notifyResult = ['success' => true, 'error' => null];
            if ($after && (string)($before['status'] ?? '') === STATUS_PLANNED) {
                $text = buildPlannedDateRangeUpdateMessage($pdo, $before, $after, $routeId);
                if ($text !== '') {
                    $notifyResult = sendMaxNotification($text);
                }
            } elseif ($after && (string)($before['status'] ?? '') === STATUS_FOUND) {
                $text = buildFoundDiffMessage($pdo, $before, $after, $routeId);
                if ($text !== '') {
                    $notifyResult = sendMaxNotification($text);
                }
            } elseif ($after && (string)($before['status'] ?? '') === STATUS_STARTED) {
                $text = buildStartedDiffMessage($pdo, $before, $after, $routeId);
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

        $managerColumn = resolveFlightsManagerColumn($pdo);
        if ($managerColumn === null) {
            jsonOut([
                'success' => false,
                'message' => 'Выберите менеджера для планируемого рейса.',
                'errors' => [
                    'assigned_manager_id' => 'Выберите менеджера для планируемого рейса.'
                ]
            ]);
        }
        $quotedManagerColumn = quoteIdent($managerColumn);

        $stmt = $pdo->prepare("
            INSERT INTO flights (status, comment, cost, unload_type, zayavki_ids, zayavki_count, {$quotedManagerColumn}, planned_start_date_from, planned_start_date_to, driver_id, block_date)
            VALUES (:status, :comment, :cost, :unload_type, :zayavki_ids, :count, :assigned_manager_id, :planned_from, :planned_to, :driver_id, NOW())
        ");
        $stmt->execute([
            ':status' => STATUS_PLANNED,
            ':comment' => $name !== '' ? $name : 'Новый рейс',
            ':cost' => $normalized['cost'],
            ':unload_type' => $normalized['unload_type'],
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
        [$title, $manager] = buildCompactFlightContext($pdo, $flight, $routeId);
        $stmt = $pdo->prepare('DELETE FROM flights WHERE id = :id AND status = :status');
        $stmt->execute([':id' => $routeId, ':status' => STATUS_PLANNED]);
        $notifyResult = sendMaxNotification(
            "#{$routeId} {$title} - Удален из системы\n" .
            "Рейс закреплен: {$manager}"
        );
        jsonOut([
            'success' => true,
            'message' => 'Маршрут удален',
            'notify_success' => (bool)$notifyResult['success'],
            'notify_error' => $notifyResult['error']
        ]);
    }

    if ($action === 'transition') {
        $target = trim((string)($data['target_status'] ?? ''));
        if (!in_array($target, [STATUS_PLANNED, STATUS_FOUND, STATUS_STARTED, STATUS_COMPLETED], true)) {
            jsonOut(['success' => false, 'message' => 'Некорректный целевой статус']);
        }
        $deny = assertTransitionAllowed($flight, $target);
        if ($deny !== null) {
            jsonOut(['success' => false, 'message' => $deny]);
        }

        if ($target === STATUS_FOUND) {
            [$ok, $msg, $normalized, $errors] = validateRouteData($pdo, $data, true, true);
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
                    unload_type = :unload_type,
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
                ':unload_type' => $normalized['unload_type'],
                ':driver_id' => $normalized['driver_id'],
                ':id' => $routeId,
            ]);
            $flight = loadFlightSnapshot($pdo, $routeId) ?: $flight;
        }

        if ($target === STATUS_STARTED) {
            $actualRaw = trim((string)($data['actual_start_date'] ?? ''));
            if ($actualRaw === '') {
                $actualRaw = trim((string)($flight['planned_start_date_from'] ?? ''));
            }
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
            $afterStarted = loadFlightSnapshot($pdo, $routeId) ?: $flight;
            [$title, $manager, $driver, $meta] = buildCompactFlightContext($pdo, $afterStarted, $routeId);
            $notifyResult = sendMaxNotification(
                "Рейс #{$routeId} → «Вывоз начался»\n" .
                "{$title} | {$manager}\n" .
                "{$meta}\n" .
                "Водитель: {$driver}\n" .
                "Старт: " . formatDateShortRu($afterStarted['actual_start_date'] ?? '') . "\n" .
                "Включён контроль перевозки."
            );
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ВЫВОЗНАЧАЛСЯ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_COMPLETED) {
            $actualEndRaw = trim((string)($data['actual_end_date'] ?? ''));
            if ($actualEndRaw === '') {
                jsonOut(['success' => false, 'message' => 'Укажите дату завершения перевозки.']);
            }
            $actualEndTs = strtotime($actualEndRaw);
            if ($actualEndTs === false) {
                jsonOut(['success' => false, 'message' => 'Некорректная дата завершения перевозки']);
            }
            if ((int)($flight['driver_id'] ?? 0) <= 0) {
                jsonOut(['success' => false, 'message' => 'Для завершения рейса укажите водителя.']);
            }
            if (count((array)($flight['_ids'] ?? [])) === 0) {
                jsonOut(['success' => false, 'message' => 'Для завершения рейса укажите заявки.']);
            }
            $stmt = $pdo->prepare('UPDATE flights SET status = :status, actual_end_date = :actual_end WHERE id = :id LIMIT 1');
            $stmt->execute([
                ':status' => STATUS_COMPLETED,
                ':actual_end' => date('Y-m-d H:i:s', $actualEndTs),
                ':id' => $routeId,
            ]);
            $afterCompleted = loadFlightSnapshot($pdo, $routeId) ?: $flight;
            $notifyResult = sendMaxNotification(
                "Рейс #{$routeId} переведен в статус ГРУЗСДАН\n" .
                "Дата завершения: " . formatDateRu($afterCompleted['actual_end_date'] ?? '') . "\n" .
                "Водитель: {$afterCompleted['_driver_label']}\n" .
                "Заявок: {$afterCompleted['_count']}\n" .
                "Масса: " . formatTons($afterCompleted['_sum_tons']) . " т\n" .
                "Груз сдан. Рейс завершён."
            );
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ГРУЗСДАН',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        $stmt = $pdo->prepare('UPDATE flights SET status = :status WHERE id = :id LIMIT 1');
        $stmt->execute([':status' => $target, ':id' => $routeId]);
        $after = loadFlightSnapshot($pdo, $routeId) ?: $flight;

        if ($target === STATUS_FOUND) {
            [$title, $manager] = buildCompactFlightContext($pdo, $after, $routeId);
            $wasStarted = (string)($flight['status'] ?? '') === STATUS_STARTED;
            if ($wasStarted) {
                $message = "Рейс #{$routeId} возвращён в «Исполнит. найден»\n" .
                    "{$title} | {$manager}\n" .
                    "Проверьте даты и подготовку документов.";
            } else {
                $message = buildPlannedToFoundMessage($pdo, $after, $routeId);
            }
            $notifyResult = sendMaxNotification($message);
            jsonOut([
                'success' => true,
                'message' => 'Рейс переведен в ИСПОЛНИТЕЛЬНАЙДЕН',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_PLANNED) {
            [$title, $manager] = buildCompactFlightContext($pdo, $after, $routeId);
            $notifyResult = sendMaxNotification(
                "#{$routeId} {$title}\n" .
                "возвращён в «Планируемый»\n" .
                "Рейс закреплен: {$manager}\n" .
                "> Подготовку документов приостановить до переформирования рейса."
            );
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


