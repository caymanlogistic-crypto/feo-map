<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

header('Content-Type: application/json; charset=utf-8');

const STATUS_PLANNED = 'planned_route';
const STATUS_FOUND = 'found';
const STATUS_STARTED = 'started';

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fmtDateShort($value): string
{
    $v = trim((string)$value);
    if ($v === '') return 'РЅРµ СѓРєР°Р·Р°РЅРѕ';
    $ts = strtotime($v);
    return $ts === false ? $v : date('d.m', $ts);
}

function fmtKg($tons): string
{
    return number_format((int)round((float)$tons * 1000), 0, '.', ' ') . ' РєРі';
}

function compactDriver(string $label): string
{
    $v = trim($label);
    if ($v === '') return 'Р’РѕРґРёС‚РµР»СЊ РЅРµ СѓРєР°Р·Р°РЅ';
    if (preg_match('/([Рђ-РЇРЃA-Z]\d{3}[Рђ-РЇРЃA-Z]{2}\d{2,3})/u', $v, $mPlate)) {
        $plate = trim($mPlate[1]);
        if (preg_match('/\(([^)]+)\)/u', $v, $mName)) {
            $surname = trim((string)explode(' ', trim($mName[1]))[0]);
            if ($surname !== '') return $plate . ' (' . $surname . ')';
        }
        return $plate;
    }
    return $v;
}

function managerName(PDO $pdo, $managerId): string
{
    $id = (int)$managerId;
    if ($id <= 0) return 'РњРµРЅРµРґР¶РµСЂ РЅРµ СѓРєР°Р·Р°РЅ';
    try {
        $stmt = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(`Р¤Р°РјРёР»РёСЏ`,''),' ',COALESCE(`РРјСЏ`,''))) AS n FROM users WHERE id=:id LIMIT 1");
        if ($stmt && $stmt->execute([':id' => $id])) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $n = trim((string)($row['n'] ?? ''));
            if ($n !== '') return $n;
        }
    } catch (Throwable $e) {
        mapError('notify_route_control managerName failed', ['id' => $id, 'error' => $e->getMessage()]);
    }
    return 'РњРµРЅРµРґР¶РµСЂ #' . $id;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $mode = trim((string)($_GET['mode'] ?? ''));
    if ($mode === '') {
        $hour = (int)date('G');
        $mode = $hour < 12 ? 'morning' : 'evening';
    }
    if (!in_array($mode, ['morning', 'evening'], true)) {
        $mode = 'morning';
    }

    $sql = "
        SELECT f.id, f.status, f.comment, f.cost, f.unload_type, f.zayavki_ids, f.assigned_manager_id,
               f.planned_start_date_from, f.planned_start_date_to,
               COALESCE(f.zayavki_count, 0) AS zayavki_count,
               d.vehicle_make_plate, d.full_name,
               (SELECT COALESCE(SUM(COALESCE(mass_netto,0)),0) FROM feo WHERE FIND_IN_SET(zayavka_id, f.zayavki_ids) > 0) AS sum_tons
        FROM flights f
        LEFT JOIN drivers d ON d.id = f.driver_id
        WHERE DATE(f.planned_start_date_from) = CURDATE()
          AND f.status IN ('planned_route', 'found')
        ORDER BY f.status ASC, f.id DESC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    if (empty($rows)) {
        out(['success' => true, 'mode' => $mode, 'sent' => 0, 'message' => 'РџСЂРѕР±Р»РµРјРЅС‹С… СЂРµР№СЃРѕРІ РЅРµС‚']);
    }

    $header = $mode === 'morning' ? 'MAX: РєРѕРЅС‚СЂРѕР»СЊ РІС‹РІРѕР·Р° РЅР° СЃРµРіРѕРґРЅСЏ' : 'MAX: РІРµС‡РµСЂРЅРёР№ РєРѕРЅС‚СЂРѕР»СЊ РІС‹РІРѕР·Р°';
    $lines = [$header];

    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $title = trim((string)($row['comment'] ?? ''));
        if ($title === '') $title = 'Р РµР№СЃ #' . $id;
        $manager = managerName($pdo, $row['assigned_manager_id'] ?? 0);
        $status = ((string)($row['status'] ?? '') === STATUS_FOUND) ? 'Рейс сформирован' : 'Планируемый';
        $driverRaw = trim((string)($row['vehicle_make_plate'] ?? ''));
        $full = trim((string)($row['full_name'] ?? ''));
        if ($full !== '') $driverRaw .= ($driverRaw !== '' ? " ({$full})" : $full);
        $driver = compactDriver($driverRaw);
        $count = (int)($row['zayavki_count'] ?? 0);
        $kg = fmtKg((float)($row['sum_tons'] ?? 0));
        $unload = strtoupper(trim((string)($row['unload_type'] ?? 'OO'))) === 'SKLAD' ? 'РЎРљР›РђР”' : 'РћРћ';
        $period = fmtDateShort($row['planned_start_date_from'] ?? '') . 'вЂ“' . fmtDateShort($row['planned_start_date_to'] ?? '');

        $lines[] = "#{$id} {$title} | {$manager}";
        $lines[] = "РЎС‚Р°С‚СѓСЃ: {$status}";
        $lines[] = "{$count} Р·Р°СЏРІ. вЂў {$kg} вЂў {$unload}";
        $lines[] = "Р’РѕРґРёС‚РµР»СЊ: {$driver}";
        $lines[] = "Р”Р°С‚С‹: {$period}";
        if ((string)($row['status'] ?? '') === STATUS_FOUND) {
            $lines[] = $mode === 'morning'
                ? 'Р•СЃР»Рё РІС‹РІРѕР· РЅР°С‡Р°Р»СЃСЏ вЂ” РїРµСЂРµРІРµРґРёС‚Рµ СЂРµР№СЃ РІ В«Р’С‹РІРѕР· РЅР°С‡Р°Р»СЃСЏВ».'
                : 'Р•СЃР»Рё РІС‹РІРѕР· РЅР°С‡Р°Р»СЃСЏ вЂ” РїРµСЂРµРІРµРґРёС‚Рµ СЂРµР№СЃ РІ В«Р’С‹РІРѕР· РЅР°С‡Р°Р»СЃСЏВ». Р•СЃР»Рё РґР°С‚Р° РёР·РјРµРЅРёР»Р°СЃСЊ вЂ” СЃРєРѕСЂСЂРµРєС‚РёСЂСѓР№С‚Рµ РґР°С‚С‹ СЂРµР№СЃР°.';
        } else {
            $lines[] = 'РќР°Р·РЅР°С‡СЊС‚Рµ РІРѕРґРёС‚РµР»СЏ/СЃС‚РѕРёРјРѕСЃС‚СЊ РёР»Рё РёР·РјРµРЅРёС‚Рµ РґР°С‚Сѓ РІС‹РІРѕР·Р°.';
        }
    }

    $text = implode("\n", $lines);
    $notify = sendMaxNotify($text);
    out([
        'success' => true,
        'mode' => $mode,
        'sent' => 1,
        'notify_success' => (bool)$notify['success'],
        'notify_error' => $notify['error']
    ]);
} catch (Throwable $e) {
    mapError('notify_route_control fatal', ['error' => $e->getMessage()]);
    out(['success' => false, 'message' => 'Р’РЅСѓС‚СЂРµРЅРЅСЏСЏ РѕС€РёР±РєР°']);
}
