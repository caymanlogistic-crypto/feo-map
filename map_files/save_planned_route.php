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
        return 'РЅРµ СѓРєР°Р·Р°РЅРѕ';
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return $v;
    }
    return date('d.m.Y H:i', $ts);
}

function sendMaxNotification($text): array
{
    return sendMaxNotify((string)$text, 'markdown');
}

function getDriverLabelById(PDO $pdo, $driverId): string
{
    $driverId = (int)$driverId;
    if ($driverId <= 0) {
        return 'Р’РѕРґРёС‚РµР»СЊ РЅРµ СѓРєР°Р·Р°РЅ';
    }
    try {
        $stmt = $pdo->prepare('SELECT full_name, vehicle_make_plate FROM drivers WHERE id = ? LIMIT 1');
        if (!$stmt || !$stmt->execute([$driverId])) {
            return 'Р’РѕРґРёС‚РµР»СЊ РЅРµ СѓРєР°Р·Р°РЅ';
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 'Р’РѕРґРёС‚РµР»СЊ РЅРµ СѓРєР°Р·Р°РЅ';
        }
        $fullName = trim((string)($row['full_name'] ?? ''));
        $plate = trim((string)($row['vehicle_make_plate'] ?? ''));
        if ($fullName === '' && $plate === '') {
            return 'Р’РѕРґРёС‚РµР»СЊ РЅРµ СѓРєР°Р·Р°РЅ';
        }
        return trim(($fullName !== '' ? $fullName : 'Р’РѕРґРёС‚РµР»СЊ') . ' / ' . ($plate !== '' ? $plate : 'Р±РµР· РЅРѕРјРµСЂР°'));
    } catch (Throwable $e) {
        mapError('Driver label load failed', ['error' => $e->getMessage()]);
        return 'Р’РѕРґРёС‚РµР»СЊ РЅРµ СѓРєР°Р·Р°РЅ';
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
    return 'РќРµРґРѕРїСѓСЃС‚РёРјС‹Р№ РїРµСЂРµС…РѕРґ СЃС‚Р°С‚СѓСЃР°';
}

function normalizeDateToDb($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        throw new InvalidArgumentException('РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р°');
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
        foreach (['Р РѕР»СЊ', 'role', 'user_role', 'type'] as $candidate) {
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
            return [false, 'РЈРєР°Р¶РёС‚Рµ РєРѕРјРјРµРЅС‚Р°СЂРёР№ / Р·Р°РіРѕР»РѕРІРѕРє СЂРµР№СЃР°', [], ['comment' => 'РћР±СЏР·Р°С‚РµР»СЊРЅС‹Р№ РєРѕРјРјРµРЅС‚Р°СЂРёР№ / Р·Р°РіРѕР»РѕРІРѕРє']];
        }
    }

    $ids = normalizeIdsString($data['zayavki_ids'] ?? '');
    if (empty($ids)) {
        return [false, 'РЎРїРёСЃРѕРє Р·Р°СЏРІРѕРє РїСѓСЃС‚РѕР№', [], ['zayavki_ids' => 'РЎРїРёСЃРѕРє Р·Р°СЏРІРѕРє РїСѓСЃС‚РѕР№']];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtIds = $pdo->prepare("SELECT zayavka_id FROM feo WHERE zayavka_id IN ({$placeholders})");
    if (!$stmtIds || !$stmtIds->execute($ids)) {
        return [false, 'РќРµ СѓРґР°Р»РѕСЃСЊ РїСЂРѕРІРµСЂРёС‚СЊ Р·Р°СЏРІРєРё', [], ['zayavki_ids' => 'РќРµ СѓРґР°Р»РѕСЃСЊ РїСЂРѕРІРµСЂРёС‚СЊ Р·Р°СЏРІРєРё']];
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
            return [false, "Р—Р°СЏРІРєР° {$id} РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚", [], ['zayavki_ids' => "Р—Р°СЏРІРєР° {$id} РЅРµ СЃСѓС‰РµСЃС‚РІСѓРµС‚"]];
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
        if ($ts === false) return [false, 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° "РЎ"', [], ['planned_start_date_from' => 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° "РЎ"']];
        $plannedFrom = date('Y-m-d H:i:s', $ts);
    }
    if ($plannedToRaw !== '') {
        $ts = strtotime($plannedToRaw);
        if ($ts === false) return [false, 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° "РџРѕ"', [], ['planned_start_date_to' => 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° "РџРѕ"']];
        $plannedTo = date('Y-m-d H:i:s', $ts);
    }
    if ($plannedFrom !== null && $plannedTo !== null && strtotime($plannedFrom) > strtotime($plannedTo)) {
        return [false, 'Р”Р°С‚Р° "РЎ" РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РїРѕР·Р¶Рµ "РџРѕ"', [], [
            'planned_start_date_from' => 'Р”Р°С‚Р° "РЎ" РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РїРѕР·Р¶Рµ "РџРѕ"',
            'planned_start_date_to' => 'Р”Р°С‚Р° "РЎ" РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РїРѕР·Р¶Рµ "РџРѕ"'
        ]];
    }

    $cost = null;
    if ($costRaw !== null && $costRaw !== '') {
        if (!is_numeric($costRaw)) return [false, 'РЎС‚РѕРёРјРѕСЃС‚СЊ РґРѕР»Р¶РЅР° Р±С‹С‚СЊ С‡РёСЃР»РѕРј', [], ['cost' => 'РЎС‚РѕРёРјРѕСЃС‚СЊ РґРѕР»Р¶РЅР° Р±С‹С‚СЊ С‡РёСЃР»РѕРј']];
        $cost = (float)$costRaw;
    }

    $driverOk = false;
    if ($driverId > 0) {
        $stmtDriver = $pdo->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
        if ($stmtDriver && $stmtDriver->execute([$driverId])) {
            $driverOk = (bool)$stmtDriver->fetch(PDO::FETCH_ASSOC);
        }
    }
    if (!$driverOk && $requireFullForFoundTransition) return [false, 'РќРµ РІС‹Р±СЂР°РЅ РєРѕСЂСЂРµРєС‚РЅС‹Р№ РІРѕРґРёС‚РµР»СЊ', [], ['driver_id' => 'РќРµ РІС‹Р±СЂР°РЅ РєРѕСЂСЂРµРєС‚РЅС‹Р№ РІРѕРґРёС‚РµР»СЊ']];
    if ($requireFullForFoundTransition && ($plannedFrom === null || $plannedTo === null)) {
        return [false, 'Для перевода в "Рейс сформирован" обязательны обе даты', [], [
            'planned_start_date_from' => 'РћР±СЏР·Р°С‚РµР»СЊРЅР°СЏ РґР°С‚Р°',
            'planned_start_date_to' => 'РћР±СЏР·Р°С‚РµР»СЊРЅР°СЏ РґР°С‚Р°'
        ]];
    }
    if ($requireFullForFoundTransition && $cost === null) return [false, 'Для перевода в "Рейс сформирован" обязательна стоимость', [], ['cost' => 'Обязательная стоимость']];

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
    return strtoupper(trim((string)$type)) === 'SKLAD' ? 'РЎРљР›РђР”' : 'РћРћ';
}

function formatDateShortRu($value): string
{
    $v = trim((string)$value);
    if ($v === '') return 'РЅРµ СѓРєР°Р·Р°РЅРѕ';
    $ts = strtotime($v);
    return $ts === false ? $v : date('d.m', $ts);
}

function formatDateRangeShortRu($from, $to): string
{
    $fromShort = formatDateShortRu($from);
    $toShort = formatDateShortRu($to);
    if ($fromShort === 'РЅРµ СѓРєР°Р·Р°РЅРѕ' && $toShort === 'РЅРµ СѓРєР°Р·Р°РЅРѕ') return 'РЅРµ СѓРєР°Р·Р°РЅРѕ';
    if ($fromShort === 'РЅРµ СѓРєР°Р·Р°РЅРѕ') return $toShort;
    if ($toShort === 'РЅРµ СѓРєР°Р·Р°РЅРѕ') return $fromShort;
    if ($fromShort === $toShort) return $fromShort;
    return $fromShort . 'вЂ“' . $toShort;
}

function formatMoneyRu($value): string
{
    if ($value === null || $value === '') return '0 в‚Ѕ';
    $num = (float)$value;
    return number_format($num, 0, '.', ' ') . ' в‚Ѕ';
}

function formatKgFromTons($tons): string
{
    return number_format((int)round((float)$tons * 1000), 0, '.', ' ') . ' РєРі';
}

function compactDriverLabel(string $label): string
{
    $v = trim($label);
    if ($v === '') return 'Водитель не указан';
    if (preg_match('/([А-ЯЁA-Z]\d{3}[А-ЯЁA-Z]{2}\d{2,3})/u', $v, $mPlate)) {
        $plate = trim($mPlate[1]);
        $surname = '';
        if (preg_match('/\(([^)]+)\)/u', $v, $mName)) {
            $surname = trim((string)explode(' ', trim($mName[1]))[0]);
        }
        if ($surname === '' && preg_match('/([А-ЯЁA-Z][а-яёa-z]+)/u', $v, $mWord)) {
            $candidate = trim((string)$mWord[1]);
            if ($candidate !== '' && stripos($candidate, 'Водител') !== 0) {
                $surname = $candidate;
            }
        }
        if ($surname !== '') return $plate . '(' . $surname . ')';
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
    return $title !== '' ? $title : ('Р РµР№СЃ #' . $flightId);
}

function buildCompactMetaLine(array $flight): string
{
    $count = (int)($flight['_count'] ?? 0);
    $kg = formatKgFromTons((float)($flight['_sum_tons'] ?? 0));
    $meta = "{$count} Р·Р°СЏРІ. вЂў {$kg}";
    if (strtoupper(trim((string)($flight['unload_type'] ?? 'OO'))) === 'SKLAD') {
        $meta .= " вЂў РЎРљР›РђР”";
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

function splitIds(string $raw): array
{
    return normalizeIdsString($raw);
}

function formatIdsList(array $ids): string
{
    if (empty($ids)) {
        return '';
    }
    return implode(',', array_values($ids));
}

function buildAddedRemovedIds(array $beforeIds, array $afterIds): array
{
    $beforeMap = array_fill_keys($beforeIds, true);
    $afterMap = array_fill_keys($afterIds, true);
    $removed = [];
    $added = [];
    foreach ($beforeIds as $id) {
        if (!isset($afterMap[$id])) {
            $removed[] = $id;
        }
    }
    foreach ($afterIds as $id) {
        if (!isset($beforeMap[$id])) {
            $added[] = $id;
        }
    }
    return [$removed, $added];
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
        return "Р’ РїР»Р°РЅРѕРІС‹Р№ СЂРµР№СЃ РґРѕР±Р°РІР»РµРЅР° РїСЂРµРґРІР°СЂРёС‚РµР»СЊРЅР°СЏ РґР°С‚Р° РЅР°С‡Р°Р»Р° РІС‹РІРѕР·Р°\n" .
            "#{$flightId} {$title}\n" .
            "РќР°С‡Р°Р»Рѕ РІС‹РІРѕР·Р°: {$rangeAfter}\n" .
            "{$meta}\n" .
            "{$driver}\n" .
            "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}\n" .
            "> рџ’Ў *РЎРѕРѕР±С‰Р°РµРјС‹Рµ РґР°С‚С‹ РЅРѕСЃСЏС‚ РѕР·РЅР°РєРѕРјРёС‚РµР»СЊРЅС‹Р№ С…Р°СЂР°РєС‚РµСЂ Рё РјРѕРіСѓС‚ Р±С‹С‚СЊ РёР·РјРµРЅРµРЅС‹.*";
    }

    $rangeBefore = formatDateRangeShortRu($beforeFrom, $beforeTo);
    return "Р’ РїР»Р°РЅРѕРІРѕРј РјР°СЂС€СЂСѓС‚Рµ РёР·РјРµРЅРµРЅС‹ РїСЂРµРґРІР°СЂРёС‚РµР»СЊРЅС‹Рµ РґР°С‚С‹ РІС‹РІРѕР·Р°\n" .
        "#{$flightId} {$title}\n" .
        "Р‘С‹Р»Рѕ: {$rangeBefore}\n" .
        "РЎС‚Р°Р»Рѕ: {$rangeAfter}\n" .
        "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}\n" .
        "> рџ’Ў *РћР±РЅРѕРІР»РµРЅРЅС‹Рµ РґР°С‚С‹ С‚Р°РєР¶Рµ РѕР·РЅР°РєРѕРјРёС‚РµР»СЊРЅС‹Рµ Рё РјРѕРіСѓС‚ Р±С‹С‚СЊ РёР·РјРµРЅРµРЅС‹.*";
}

function buildPlannedToFoundMessage(PDO $pdo, array $after, int $flightId): string
{
    [$title, $manager, $driver, $meta] = buildCompactFlightContext($pdo, $after, $flightId);
    $dateRange = formatDateRangeShortRu($after['planned_start_date_from'] ?? '', $after['planned_start_date_to'] ?? '');
    return "**Р Р•Р™РЎ РЎР¤РћР РњРР РћР’РђРќ**\n" .
        "#{$flightId} {$title}\n" .
        "РќР°С‡Р°Р»Рѕ РІС‹РІРѕР·Р°: {$dateRange}\n" .
        "{$meta}\n" .
        "{$driver}\n" .
        "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}\n" .
        "> рџ’Ў *РџСЂРѕСЃРёРј РїРѕРґРіРѕС‚РѕРІРёС‚СЊ С‚РѕРІР°СЂРѕСЃРѕРїСЂРѕРІРѕРґРёС‚РµР»СЊРЅС‹Рµ РґРѕРєСѓРјРµРЅС‚С‹ РЅР° Р·Р°СЏРІР»РµРЅРЅС‹Рµ РґР°С‚Сѓ Рё РІРѕРґРёС‚РµР»СЏ.*";
}

function buildFoundDiffMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    [$title, $manager] = buildCompactFlightContext($pdo, $after, $flightId);
    $changes = [];
    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $changes[] = 'Р’РѕРґРёС‚РµР»СЊ: ' . compactDriverLabel((string)$before['_driver_label']) . ' в†’ ' . compactDriverLabel((string)$after['_driver_label']);
    }
    if ((string)$before['planned_start_date_from'] !== (string)$after['planned_start_date_from']) {
        $changes[] = 'Р”Р°С‚С‹: ' . formatDateShortRu($before['planned_start_date_from']) . '-' . formatDateShortRu($before['planned_start_date_to'])
            . ' в†’ ' . formatDateShortRu($after['planned_start_date_from']) . '-' . formatDateShortRu($after['planned_start_date_to']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        $changes[] = 'Р—Р°СЏРІРєРё: ' . (int)$before['_count'] . ' в†’ ' . (int)$after['_count'];
        [$removed, $added] = buildAddedRemovedIds(splitIds((string)($before['zayavki_ids'] ?? '')), splitIds((string)($after['zayavki_ids'] ?? '')));
        if (!empty($removed)) {
            $changes[] = 'РСЃРєР»СЋС‡РµРЅРЅС‹Рµ Р·Р°СЏРІРєРё: ' . formatIdsList($removed);
        }
        if (!empty($added)) {
            $changes[] = 'Р”РѕР±Р°РІР»РµРЅРЅС‹Рµ Р·Р°СЏРІРєРё: ' . formatIdsList($added);
        }
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $changes[] = 'Р’РµСЃ: ' . formatKgFromTons((float)$before['_sum_tons']) . ' в†’ ' . formatKgFromTons((float)$after['_sum_tons']);
    }

    if ((string)($before['unload_type'] ?? 'OO') !== (string)($after['unload_type'] ?? 'OO')) {
        $changes[] = 'РўРёРї РІС‹РіСЂСѓР·РєРё: ' . formatUnloadTypeRu($before['unload_type'] ?? 'OO') . ' в†’ ' . formatUnloadTypeRu($after['unload_type'] ?? 'OO');
    }
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) {
        $changes[] = 'РќР°Р·РІР°РЅРёРµ: ' . buildRouteTitle($before, $flightId) . ' в†’ ' . buildRouteTitle($after, $flightId);
    }

    if (empty($changes)) {
        return '';
    }

    return "**вљ пёЏ РР—РњР•РќР•РќРР• Р’ РЎР¤РћР РњРР РћР’РђРќРќРћРњ Р Р•Р™РЎР• вљ пёЏ**\n#{$flightId} {$title}\n" . implode("\n", $changes) . "\nР РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}";
}


function buildStartedDiffMessage(PDO $pdo, array $before, array $after, int $flightId): string
{
    [$title, $manager] = buildCompactFlightContext($pdo, $after, $flightId);
    $changes = [];
    if ((int)$before['driver_id'] !== (int)$after['driver_id']) {
        $changes[] = 'Р’РѕРґРёС‚РµР»СЊ: ' . compactDriverLabel((string)$before['_driver_label']) . ' в†’ ' . compactDriverLabel((string)$after['_driver_label']);
    }
    if ((string)$before['actual_start_date'] !== (string)$after['actual_start_date']) {
        $changes[] = 'РЎС‚Р°СЂС‚: ' . formatDateShortRu($before['actual_start_date']) . ' в†’ ' . formatDateShortRu($after['actual_start_date']);
    }
    if ((string)$before['actual_end_date'] !== (string)$after['actual_end_date']) {
        $changes[] = 'Р¤РёРЅРёС€: ' . formatDateShortRu($before['actual_end_date']) . ' в†’ ' . formatDateShortRu($after['actual_end_date']);
    }
    if ((string)$before['zayavki_ids'] !== (string)$after['zayavki_ids']) {
        $changes[] = 'Р—Р°СЏРІРєРё: ' . (int)$before['_count'] . ' в†’ ' . (int)$after['_count'];
        [$removed, $added] = buildAddedRemovedIds(splitIds((string)($before['zayavki_ids'] ?? '')), splitIds((string)($after['zayavki_ids'] ?? '')));
        if (!empty($removed)) {
            $changes[] = 'РСЃРєР»СЋС‡РµРЅРЅС‹Рµ Р·Р°СЏРІРєРё: ' . formatIdsList($removed);
        }
        if (!empty($added)) {
            $changes[] = 'Р”РѕР±Р°РІР»РµРЅРЅС‹Рµ Р·Р°СЏРІРєРё: ' . formatIdsList($added);
        }
    }
    if (abs((float)$before['_sum_tons'] - (float)$after['_sum_tons']) > 0.0001) {
        $changes[] = 'Р’РµСЃ: ' . formatKgFromTons((float)$before['_sum_tons']) . ' в†’ ' . formatKgFromTons((float)$after['_sum_tons']);
    }
    if (trim((string)($before['comment'] ?? '')) !== trim((string)($after['comment'] ?? ''))) {
        $changes[] = 'РќР°Р·РІР°РЅРёРµ: ' . buildRouteTitle($before, $flightId) . ' в†’ ' . buildRouteTitle($after, $flightId);
    }
    if ((string)($before['unload_type'] ?? 'OO') !== (string)($after['unload_type'] ?? 'OO')) {
        $changes[] = 'РўРёРї РІС‹РіСЂСѓР·РєРё: ' . formatUnloadTypeRu($before['unload_type'] ?? 'OO') . ' в†’ ' . formatUnloadTypeRu($after['unload_type'] ?? 'OO');
    }

    if (empty($changes)) {
        return '';
    }
    return "РР·РјРµРЅС‘РЅ СЂРµР№СЃ #{$flightId} РІРѕ РІСЂРµРјСЏ РІС‹РїРѕР»РЅРµРЅРёСЏ\n{$title} | {$manager}\n" . implode("\n", $changes) . "\nР РµР№СЃ РЅР°С…РѕРґРёС‚СЃСЏ РІ РІС‹РїРѕР»РЅРµРЅРёРё. РџСЂРѕРІРµСЂСЊС‚Рµ РєРѕСЂСЂРµРєС‚РЅРѕСЃС‚СЊ РёР·РјРµРЅРµРЅРёР№.";
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['success' => false, 'message' => 'РќРµРІРµСЂРЅС‹Р№ РјРµС‚РѕРґ']);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }

    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('РќРµРІРµСЂРЅС‹Р№ JSON');
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
            if (!$before) jsonOut(['success' => false, 'message' => 'Р РµР№СЃ РЅРµ РЅР°Р№РґРµРЅ']);

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
                    jsonOut(['success' => false, 'message' => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Рµ С„Р°РєС‚РёС‡РµСЃРєРёРµ РґР°С‚С‹']);
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
                ':comment' => $name !== '' ? $name : ($before['comment'] ?? ('Р РµР№СЃ #' . $routeId)),
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
                'message' => 'Р РµР№СЃ РѕР±РЅРѕРІР»С‘РЅ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        $assignedManagerId = resolveValidLogistManagerId($pdo, $data['assigned_manager_id'] ?? 0);
        if ($assignedManagerId <= 0) {
            jsonOut([
                'success' => false,
                'message' => 'Р’С‹Р±РµСЂРёС‚Рµ РјРµРЅРµРґР¶РµСЂР° РґР»СЏ РїР»Р°РЅРёСЂСѓРµРјРѕРіРѕ СЂРµР№СЃР°.',
                'errors' => [
                    'assigned_manager_id' => 'Р’С‹Р±РµСЂРёС‚Рµ РјРµРЅРµРґР¶РµСЂР° РґР»СЏ РїР»Р°РЅРёСЂСѓРµРјРѕРіРѕ СЂРµР№СЃР°.'
                ]
            ]);
        }

        $managerColumn = resolveFlightsManagerColumn($pdo);
        if ($managerColumn === null) {
            jsonOut([
                'success' => false,
                'message' => 'Р’С‹Р±РµСЂРёС‚Рµ РјРµРЅРµРґР¶РµСЂР° РґР»СЏ РїР»Р°РЅРёСЂСѓРµРјРѕРіРѕ СЂРµР№СЃР°.',
                'errors' => [
                    'assigned_manager_id' => 'Р’С‹Р±РµСЂРёС‚Рµ РјРµРЅРµРґР¶РµСЂР° РґР»СЏ РїР»Р°РЅРёСЂСѓРµРјРѕРіРѕ СЂРµР№СЃР°.'
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
            ':comment' => $name !== '' ? $name : 'РќРѕРІС‹Р№ СЂРµР№СЃ',
            ':cost' => $normalized['cost'],
            ':unload_type' => $normalized['unload_type'],
            ':zayavki_ids' => $normalized['zayavki_ids_canonical'],
            ':count' => $normalized['zayavki_count'],
            ':assigned_manager_id' => $assignedManagerId,
            ':planned_from' => $normalized['planned_start_date_from'],
            ':planned_to' => $normalized['planned_start_date_to'],
            ':driver_id' => $normalized['driver_id'],
        ]);
        jsonOut(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'РњР°СЂС€СЂСѓС‚ СЃРѕР·РґР°РЅ']);
    }

    if ($routeId <= 0) {
        jsonOut(['success' => false, 'message' => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ ID СЂРµР№СЃР°']);
    }
    $flight = loadFlightSnapshot($pdo, $routeId);
    if (!$flight) {
        jsonOut(['success' => false, 'message' => 'Р РµР№СЃ РЅРµ РЅР°Р№РґРµРЅ']);
    }

    if ($action === 'delete_route') {
        if ((string)$flight['status'] !== STATUS_PLANNED) {
            jsonOut(['success' => false, 'message' => 'РЈРґР°Р»РµРЅРёРµ РґРѕСЃС‚СѓРїРЅРѕ С‚РѕР»СЊРєРѕ РґР»СЏ РџР›РђРќРР РЈР•РњР«Р™']);
        }
        [$title, $manager] = buildCompactFlightContext($pdo, $flight, $routeId);
        $stmt = $pdo->prepare('DELETE FROM flights WHERE id = :id AND status = :status');
        $stmt->execute([':id' => $routeId, ':status' => STATUS_PLANNED]);
        $notifyResult = sendMaxNotification(
            "#{$routeId} {$title} - РЈРґР°Р»РµРЅ РёР· СЃРёСЃС‚РµРјС‹\n" .
            "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}"
        );
        jsonOut([
            'success' => true,
            'message' => 'РњР°СЂС€СЂСѓС‚ СѓРґР°Р»РµРЅ',
            'notify_success' => (bool)$notifyResult['success'],
            'notify_error' => $notifyResult['error']
        ]);
    }

    if ($action === 'transition') {
        $target = trim((string)($data['target_status'] ?? ''));
        if (!in_array($target, [STATUS_PLANNED, STATUS_FOUND, STATUS_STARTED, STATUS_COMPLETED], true)) {
            jsonOut(['success' => false, 'message' => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ С†РµР»РµРІРѕР№ СЃС‚Р°С‚СѓСЃ']);
        }
        $deny = assertTransitionAllowed($flight, $target);
        if ($deny !== null) {
            jsonOut(['success' => false, 'message' => $deny]);
        }

        if ($target === STATUS_FOUND) {
            $wasStarted = (string)($flight['status'] ?? '') === STATUS_STARTED;
            $payload = $data;
            if ($wasStarted) {
                if (!isset($payload['zayavki_ids']) || trim((string)$payload['zayavki_ids']) === '') $payload['zayavki_ids'] = (string)($flight['zayavki_ids'] ?? '');
                if (!isset($payload['driver_id']) || (int)$payload['driver_id'] <= 0) $payload['driver_id'] = (int)($flight['driver_id'] ?? 0);
                if (!isset($payload['planned_start_date_from']) || trim((string)$payload['planned_start_date_from']) === '') $payload['planned_start_date_from'] = (string)($flight['planned_start_date_from'] ?? '');
                if (!isset($payload['planned_start_date_to']) || trim((string)$payload['planned_start_date_to']) === '') $payload['planned_start_date_to'] = (string)($flight['planned_start_date_to'] ?? '');
                if (!array_key_exists('cost', $payload) || $payload['cost'] === '' || $payload['cost'] === null) $payload['cost'] = $flight['cost'] ?? null;
                if (!isset($payload['unload_type']) || trim((string)$payload['unload_type']) === '') $payload['unload_type'] = (string)($flight['unload_type'] ?? 'OO');
                if (!isset($payload['name']) || trim((string)$payload['name']) === '') $payload['name'] = trim((string)($flight['comment'] ?? $flight['name'] ?? ''));
            }
            $requireTitle = !$wasStarted;
            [$ok, $msg, $normalized, $errors] = validateRouteData($pdo, $payload, !$wasStarted, $requireTitle);
            if (!$ok) {
                jsonOut(['success' => false, 'message' => $msg, 'errors' => $errors]);
            }
            $comment = trim((string)($payload['name'] ?? ''));
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
                jsonOut(['success' => false, 'message' => 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° РЅР°С‡Р°Р»Р° РІС‹РІРѕР·Р°']);
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
                "**вњ… Р’Р«Р’РћР— РќРђР§РђР›РЎРЇ**\n" .
                "#{$routeId} {$title}\n" .
                "Р’РѕРґРёС‚РµР»СЊ: {$driver}\n" .
                "РЎС‚Р°СЂС‚: " . formatDateShortRu($afterStarted['actual_start_date'] ?? '') . "\n" .
                "Р—Р°СЏРІРєРё: " . (int)($afterStarted['_count'] ?? 0) . "\n" .
                "Р’РµСЃ: " . formatKgFromTons((float)($afterStarted['_sum_tons'] ?? 0)) . "\n" .
                "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}\n" .
                "> рџ’Ў *Р’РєР»СЋС‡РµРЅРѕ СЃР»РµР¶РµРЅРёРµ Р·Р° СЃРѕСЃС‚РѕСЏРЅРёРµРј С‚СЂРµРєРµСЂР°.*"
            );
            jsonOut([
                'success' => true,
                'message' => 'Р РµР№СЃ РїРµСЂРµРІРµРґРµРЅ РІ Р’Р«Р’РћР—РќРђР§РђР›РЎРЇ',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_COMPLETED) {
            $actualEndRaw = trim((string)($data['actual_end_date'] ?? ''));
            if ($actualEndRaw === '') {
                jsonOut(['success' => false, 'message' => 'РЈРєР°Р¶РёС‚Рµ РґР°С‚Сѓ Р·Р°РІРµСЂС€РµРЅРёСЏ РїРµСЂРµРІРѕР·РєРё.']);
            }
            $actualEndTs = strtotime($actualEndRaw);
            if ($actualEndTs === false) {
                jsonOut(['success' => false, 'message' => 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ РґР°С‚Р° Р·Р°РІРµСЂС€РµРЅРёСЏ РїРµСЂРµРІРѕР·РєРё']);
            }
            if ((int)($flight['driver_id'] ?? 0) <= 0) {
                jsonOut(['success' => false, 'message' => 'Р”Р»СЏ Р·Р°РІРµСЂС€РµРЅРёСЏ СЂРµР№СЃР° СѓРєР°Р¶РёС‚Рµ РІРѕРґРёС‚РµР»СЏ.']);
            }
            if (count((array)($flight['_ids'] ?? [])) === 0) {
                jsonOut(['success' => false, 'message' => 'Р”Р»СЏ Р·Р°РІРµСЂС€РµРЅРёСЏ СЂРµР№СЃР° СѓРєР°Р¶РёС‚Рµ Р·Р°СЏРІРєРё.']);
            }
            $stmt = $pdo->prepare('UPDATE flights SET status = :status, actual_end_date = :actual_end WHERE id = :id LIMIT 1');
            $stmt->execute([
                ':status' => STATUS_COMPLETED,
                ':actual_end' => date('Y-m-d H:i:s', $actualEndTs),
                ':id' => $routeId,
            ]);
            $afterCompleted = loadFlightSnapshot($pdo, $routeId) ?: $flight;
            $driver = compactDriverLabel((string)($afterCompleted['_driver_label'] ?? ''));
            $title = buildRouteTitle($afterCompleted, $routeId);
            $notifyResult = sendMaxNotification(
                "РўРЎ РџР РР‘Р«Р›Рћ РќРђ Р РђР—Р“Р РЈР—РљРЈ\n" .
                "в”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђв”Ђ\n" .
                "{$driver}\n" .
                "#{$routeId} вЂ” {$title}\n" .
                "> рџ’Ў *РќР°РїРѕРјРёРЅР°СЋ: РґР»СЏ РѕРїР»Р°С‚С‹ РїРѕРґСЂСЏРґС‡РёРєСѓ РЅСѓР¶РµРЅ РїРѕР»РЅС‹Р№ РїР°РєРµС‚ РґРѕРєСѓРјРµРЅС‚РѕРІ (РґРёР°РіРЅРѕСЃС‚РёС‡РµСЃРєР°СЏ РєР°СЂС‚Р°, РїСѓС‚РµРІРѕР№ Р»РёСЃС‚ Рё С‚.Рґ.). РџСЂРѕС€Сѓ РЅРµ Р·Р°С‚СЏРіРёРІР°С‚СЊ СЃ РїСЂРµРґРѕСЃС‚Р°РІР»РµРЅРёРµРј.*"
            );
            jsonOut([
                'success' => true,
                'message' => 'Р РµР№СЃ РїРµСЂРµРІРµРґРµРЅ РІ Р“Р РЈР—РЎР”РђРќ',
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
                $driver = compactDriverLabel((string)($after['_driver_label'] ?? ''));
                $message = "**вљ пёЏ РџР Р•РћРЎРўРђРќРћР’РљРђ Р’Р«РџРћР›РќРЇР•РњРћР“Рћ Р Р•Р™РЎРђ вљ пёЏ**\n" .
                    "#{$routeId} {$title}\n" .
                    "Р’РѕРґРёС‚РµР»СЊ: {$driver}\n" .
                    "РЎС‚Р°СЂС‚: " . formatDateShortRu($after['actual_start_date'] ?? '') . "\n" .
                    "Р—Р°СЏРІРєРё: " . (int)($after['_count'] ?? 0) . "\n" .
                    "Р’РµСЃ: " . formatKgFromTons((float)($after['_sum_tons'] ?? 0)) . "\n" .
                    "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}\n" .
                    "> рџ’Ў *Р’РќРРњРђРќРР•. РЎС‚Р°С‚СѓСЃ СЂРµР№СЃР° РёР·РјРµРЅС‘РЅ СЃ В«Р’С‹РїРѕР»РЅСЏРµРјС‹РµВ» РЅР° В«РЎС„РѕСЂРјРёСЂРѕРІР°РЅРЅС‹РµВ». Р’ СЃРІСЏР·Рё СЃ СЌС‚РёРј РІРµСЂРѕСЏС‚РЅР° РєРѕСЂСЂРµРєС‚РёСЂРѕРІРєР° РїРµСЂРµС‡РЅСЏ РІС‹РІРѕР·РёРјС‹С… Р·Р°СЏРІРѕРє Р»РёР±Рѕ Р·Р°РјРµРЅР° РїРѕРґСЂСЏРґС‡РёРєР°.*";
            } else {
                $message = buildPlannedToFoundMessage($pdo, $after, $routeId);
            }
            $notifyResult = sendMaxNotification($message);
            jsonOut([
                'success' => true,
                'message' => 'Рейс сформирован',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }

        if ($target === STATUS_PLANNED) {
            [$title, $manager] = buildCompactFlightContext($pdo, $after, $routeId);
            $notifyResult = sendMaxNotification(
                "**#{$routeId} {$title}**\n" .
                "РІРѕР·РІСЂР°С‰С‘РЅ РІ В«РџР»Р°РЅРёСЂСѓРµРјС‹Р№В»\n" .
                "Р РµР№СЃ Р·Р°РєСЂРµРїР»РµРЅ: {$manager}\n" .
                "> рџ’Ў *РџРѕРґРіРѕС‚РѕРІРєСѓ РґРѕРєСѓРјРµРЅС‚РѕРІ РїСЂРёРѕСЃС‚Р°РЅРѕРІРёС‚СЊ РґРѕ РїРµСЂРµС„РѕСЂРјРёСЂРѕРІР°РЅРёСЏ СЂРµР№СЃР°.*"
            );
            jsonOut([
                'success' => true,
                'message' => 'Р РµР№СЃ РІРѕР·РІСЂР°С‰РµРЅ РІ РџР›РђРќРР РЈР•РњР«Р™',
                'notify_success' => (bool)$notifyResult['success'],
                'notify_error' => $notifyResult['error']
            ]);
        }
    }

    jsonOut(['success' => false, 'message' => 'РќРµРёР·РІРµСЃС‚РЅРѕРµ РґРµР№СЃС‚РІРёРµ']);
} catch (Throwable $e) {
    mapError('save_planned_route fatal', ['error' => $e->getMessage()]);
    jsonOut(['success' => false, 'message' => 'Р’РЅСѓС‚СЂРµРЅРЅСЏСЏ РѕС€РёР±РєР°']);
}


