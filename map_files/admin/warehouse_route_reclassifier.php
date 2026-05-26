<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function whDate(?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00') return '—';
    return date('d.m.Y', strtotime($v));
}

function whWarehouseName(PDO $pdo, ?int $id): string {
    if (!$id || $id <= 0) return '—';
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        $stmt = $pdo->prepare('SELECT name FROM warehouses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cache[$id] = $row ? (string)$row['name'] : "ID#$id";
    } catch (Throwable $e) {
        $cache[$id] = "ID#$id";
    }
    return $cache[$id];
}

function whRouteTypeLabel(string $rt): string {
    $map = [
        ROUTE_TYPE_GENERATOR_TO_UTILIZER => 'ОО → Утилизатор',
        ROUTE_TYPE_GENERATOR_TO_WAREHOUSE => 'ОО → Временный склад',
        ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE => 'Склад → Склад',
        ROUTE_TYPE_WAREHOUSE_TO_UTILIZER => 'Склад → Утилизатор',
    ];
    return $map[$rt] ?? $rt;
}

function whLoadAllWarehouses(PDO $pdo): array {
    try {
        $stmt = $pdo->query('SELECT id, name FROM warehouses ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function whUnloadTypeByRouteType(string $rt): string {
    return $rt === ROUTE_TYPE_GENERATOR_TO_UTILIZER ? 'OO' : 'SKLAD';
}

function whStatusLabel(string $s): string {
    $map = [
        'planned_route' => 'Планируемый',
        'found' => 'Сформирован',
        'started' => 'Вывоз начался',
        'completed' => 'Груз сдан',
    ];
    return $map[$s] ?? $s;
}

/**
 * Preview expected WM rows (read-only, no INSERT).
 */
function whPreviewMovements(PDO $pdo, int $flightId, string $routeType, int $sourceWh, int $destWh): array {
    $preview = ['rows' => [], 'total' => 0, 'will_create' => 0, 'already_exist' => 0];

    $zayavkiIds = [];
    try {
        $stmt = $pdo->prepare('SELECT zayavki_ids FROM flights WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $flightId]);
        $raw = $stmt->fetchColumn();
        if ($raw) {
            foreach (explode(',', (string)$raw) as $id) {
                $id = (int)trim($id);
                if ($id > 0) $zayavkiIds[] = $id;
            }
        }
    } catch (Throwable $e) {
        return $preview;
    }
    if (empty($zayavkiIds)) return $preview;

    $feoRows = [];
    try {
        $ph = implode(',', array_fill(0, count($zayavkiIds), '?'));
        $stmtFeo = $pdo->prepare("SELECT zayavka_id, naim_otkhoda_fkko, mass_netto, mass_brutto, summarnyy_obem FROM feo WHERE zayavka_id IN ({$ph})");
        $stmtFeo->execute($zayavkiIds);
        while ($row = $stmtFeo->fetch(PDO::FETCH_ASSOC)) {
            $feoRows[(int)$row['zayavka_id']] = $row;
        }
    } catch (Throwable $e) {
        return $preview;
    }

    foreach ($zayavkiIds as $zid) {
        $feo = $feoRows[$zid] ?? null;
        $rows = [];
        if ($routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE && $destWh > 0) {
            $rows[] = [WM_MOVEMENT_RECEIPT, $destWh];
        } elseif ($routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER && $sourceWh > 0) {
            $rows[] = [WM_MOVEMENT_ISSUE, $sourceWh];
        } elseif ($routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE && $sourceWh > 0 && $destWh > 0) {
            $rows[] = [WM_MOVEMENT_TRANSFER_OUT, $sourceWh];
            $rows[] = [WM_MOVEMENT_TRANSFER_IN, $destWh];
        }
        foreach ($rows as [$movType, $whId]) {
            $exists = wmMovementExists($pdo, $flightId, $movType, $whId, $zid);
            $preview['rows'][] = [
                'movement_type' => $movType,
                'warehouse_id' => $whId,
                'warehouse_name' => whWarehouseName($pdo, $whId),
                'zayavka_id' => $zid,
                'fkko_code' => $feo ? (string)($feo['naim_otkhoda_fkko'] ?? '') : '',
                'mass_netto' => $feo ? round((float)($feo['mass_netto'] ?? 0), 3) : 0,
                'mass_brutto' => $feo ? round((float)($feo['mass_brutto'] ?? 0), 3) : 0,
                'volume' => $feo ? round((float)($feo['summarnyy_obem'] ?? 0), 3) : 0,
                'exists' => $exists,
            ];
            $preview['total']++;
            $exists ? $preview['already_exist']++ : $preview['will_create']++;
        }
    }
    return $preview;
}

// ── State ────────────────────────────────────────────────────────────────────

$flash = '';
$flashType = 'ok';
$flight = null;
$preview = null;
$wmResult = null;
$wmRowsAfter = null;
$allWarehouses = whLoadAllWarehouses($pdo);

// ── Auth ─────────────────────────────────────────────────────────────────────

if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)maxAdminPost('action') === 'login') {
    if (hash_equals(maxAdminPasswordConst(), (string)maxAdminPost('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: warehouse_route_reclassifier.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}

if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: warehouse_route_reclassifier.php');
    exit;
}

// ── POST handlers ────────────────────────────────────────────────────────────

if (maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)maxAdminPost('action');

    // OPEN by ID
    if ($action === 'open') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        if ($flightId <= 0) {
            $flash = 'Введите корректный ID рейса';
            $flashType = 'err';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $flight = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($flight)) {
                    $flash = 'Рейс #' . $flightId . ' не найден';
                    $flashType = 'err';
                }
            } catch (Throwable $e) {
                $flash = 'Ошибка: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }

    // PREVIEW
    elseif ($action === 'preview') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        $newRt = (string)maxAdminPost('new_route_type', '');
        $srcWh = (int)maxAdminPost('new_source_warehouse', '0');
        $dstWh = (int)maxAdminPost('new_destination_warehouse', '0');

        if ($flightId <= 0 || $newRt === '') {
            $flash = 'Заполните обязательные поля';
            $flashType = 'err';
        } else {
            // Reload flight
            $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $flightId]);
            $flight = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($flight)) {
                $flash = 'Рейс не найден';
                $flashType = 'err';
            } else {
                $preview = whPreviewMovements($pdo, $flightId, $newRt, $srcWh, $dstWh);
            }
        }
    }

    // APPLY
    elseif ($action === 'apply') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        $newRt = (string)maxAdminPost('new_route_type', '');
        $srcWh = (int)maxAdminPost('new_source_warehouse', '0');
        $dstWh = (int)maxAdminPost('new_destination_warehouse', '0');
        $confirmed = maxAdminPost('confirm_apply', '0') === '1';

        if (!$confirmed) {
            $flash = 'Подтвердите операцию';
            $flashType = 'err';
        } elseif ($flightId <= 0 || $newRt === '') {
            $flash = 'Заполните обязательные поля';
            $flashType = 'err';
        } else {
            try {
                // Re-read flight fresh
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $liveFlight = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($liveFlight)) {
                    $flash = 'Рейс не найден';
                    $flashType = 'err';
                } else {
                    $newUnload = whUnloadTypeByRouteType($newRt);
                    $upd = $pdo->prepare('UPDATE flights SET route_type = :rt, unload_type = :ut, source_warehouse_id = :sw, destination_warehouse_id = :dw, updated_at = NOW() WHERE id = :id');
                    $upd->execute([
                        ':rt' => $newRt,
                        ':ut' => $newUnload,
                        ':sw' => $srcWh > 0 ? $srcWh : null,
                        ':dw' => $dstWh > 0 ? $dstWh : null,
                        ':id' => $flightId,
                    ]);

                    $flash = "Рейс #{$flightId}: разметка обновлена.";
                    $flashType = 'ok';

                    // If completed — try to create WM
                    if ((string)$liveFlight['status'] === 'completed' && $newRt !== ROUTE_TYPE_GENERATOR_TO_UTILIZER) {
                        $wmResult = createWarehouseMovementsForCompletedFlight($pdo, $flightId);
                        // Read WM rows after
                        try {
                            $wms = $pdo->prepare('SELECT id, movement_type, warehouse_id, flight_id, zayavka_id, fkko_code, ROUND(mass_netto,3) mass_netto, ROUND(mass_brutto,3) mass_brutto, ROUND(volume,3) volume, movement_date, status FROM warehouse_movements WHERE flight_id = :fid ORDER BY id');
                            $wms->execute([':fid' => $flightId]);
                            $wmRowsAfter = $wms->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Throwable $e) {
                            $wmRowsAfter = [];
                        }
                    }

                    // Reload flight for card
                    $stmt->execute([':id' => $flightId]);
                    $flight = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {
                $flash = 'Ошибка: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }
}

// ── Preserve flight_id in form ───────────────────────────────────────────────

$currentFlightId = isset($flight['id']) ? (int)$flight['id'] : (int)maxAdminPost('flight_id', '0');

?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Складская разметка рейса</title>
<style>
body{margin:0;background:#f4f6f8;color:#1e293b;font-family:Arial,sans-serif}
.wrap{max-width:1200px;margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.linkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:14px;margin-bottom:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}
input[type=text],input[type=number],select{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;background:#fff;color:#1e293b}
.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center;text-decoration:none;color:#1e293b}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}
.btn.success{background:#10b981;color:#fff;border-color:#059669}
.btn:disabled{opacity:.5;cursor:not-allowed}
.small{font-size:12px;color:#64748b}
.ok{background:#ecfdf3;border-color:#b7e4c7;color:#0f766e}
.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
.warn-bg{background:#fffbeb;border-color:#fde68a;color:#92400e}
table{width:100%;border-collapse:collapse}
th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f8fafc;color:#475569;font-weight:600}
.mono{font-family:Consolas,monospace;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge-ok{background:#dcfce7;color:#166534}
.badge-warn{background:#fef3c7;color:#92400e}
.badge-info{background:#dbeafe;color:#1e40af}
.hint{font-size:12px;color:#334155;background:#f8fafc;border-left:3px solid #0ea5b7;padding:8px 10px;border-radius:4px;margin:6px 0}
.field-group{margin-bottom:4px}
.field-group label{display:block;margin-bottom:1px;font-weight:600;font-size:12px;color:#64748b}
.field-group .value{font-size:14px}
.login{max-width:420px;margin:80px auto}
.section-title{font-size:15px;font-weight:600;color:#0f172a;margin:0 0 8px 0;padding-bottom:4px;border-bottom:2px solid #0ea5b7}
@media(max-width:900px){.grid,.grid-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

<div class="top">
  <div class="linkbar">
    <h2 style="margin:0;font-size:18px">Складская разметка рейса</h2>
    <a class="btn" href="max_admin.php">MAX Admin</a>
    <a class="btn" href="max_event_center.php">Event Center</a>
  </div>
  <?php if (maxAdminIsAuthed()): ?><a class="btn" href="?logout=1">Выйти</a><?php endif; ?>
</div>

<?php if ($flash !== ''): ?>
<div class="card <?= $flashType === 'err' ? 'err' : ($flashType === 'warn' ? 'warn-bg' : 'ok') ?>"><?= maxAdminHtml($flash) ?></div>
<?php endif; ?>

<?php if (!maxAdminIsAuthed()): ?>
<div class="card login">
  <form method="post">
    <input type="hidden" name="action" value="login">
    <label>Пароль доступа</label>
    <input type="password" name="password" required style="width:100%">
    <div style="height:8px"></div>
    <button class="btn primary" type="submit">Войти</button>
  </form>
</div>

<?php else: ?>

<!-- ── ID SEARCH ──────────────────────────────────────────────────────────── -->
<div class="card">
  <form method="post">
    <input type="hidden" name="action" value="open">
    <div class="row">
      <label>ID рейса</label>
      <input type="number" name="flight_id" value="<?= $currentFlightId > 0 ? $currentFlightId : '' ?>" placeholder="например 187" min="1" style="width:140px">
      <button class="btn primary" type="submit">Открыть рейс</button>
    </div>
  </form>
</div>

<?php if (!is_array($flight)): ?>
<div class="card hint">
  Введите ID рейса, чтобы открыть его складскую разметку.
</div>

<?php else: ?>
<?php
  $currRt = (string)($flight['route_type'] ?? '');
  $currSrc = (int)($flight['source_warehouse_id'] ?? 0);
  $currDst = (int)($flight['destination_warehouse_id'] ?? 0);
  $currStatus = (string)($flight['status'] ?? '');
  $isCompleted = $currStatus === 'completed';
?>

<!-- ── FLIGHT CARD ───────────────────────────────────────────────────────── -->
<div class="card">
  <div class="section-title">Рейс #<?= (int)$flight['id'] ?> — <?= maxAdminHtml(whStatusLabel($currStatus)) ?></div>
  <div class="grid-3">
    <div class="field-group"><label>Статус</label><div class="value"><span class="badge badge-<?= $isCompleted ? 'ok' : 'info' ?>"><?= maxAdminHtml(whStatusLabel($currStatus)) ?></span></div></div>
    <div class="field-group"><label>Маршрут груза</label><div class="value"><span class="badge badge-info"><?= whRouteTypeLabel($currRt) ?></span></div></div>
    <div class="field-group"><label>Склад отправления</label><div class="value"><?= maxAdminHtml(whWarehouseName($pdo, $currSrc)) ?></div></div>
    <div class="field-group"><label>Склад назначения</label><div class="value"><?= maxAdminHtml(whWarehouseName($pdo, $currDst)) ?></div></div>
    <div class="field-group"><label>Заявки</label><div class="value mono"><?= maxAdminHtml($flight['zayavki_ids'] ?? '—') ?> (<?= (int)($flight['zayavki_count'] ?? 0) ?> шт.)</div></div>
    <div class="field-group"><label>Масса, т</label><div class="value"><?= round((float)($flight['total_mass_tonn'] ?? 0), 3) ?></div></div>
    <div class="field-group"><label>План. начало</label><div class="value"><?= whDate($flight['planned_start_date'] ?? null) ?></div></div>
    <div class="field-group"><label>План. от</label><div class="value"><?= whDate($flight['planned_start_date_from'] ?? null) ?></div></div>
    <div class="field-group"><label>План. до</label><div class="value"><?= whDate($flight['planned_start_date_to'] ?? null) ?></div></div>
    <div class="field-group"><label>Факт. начало</label><div class="value"><?= whDate($flight['actual_start_date'] ?? null) ?></div></div>
    <div class="field-group"><label>Факт. конец</label><div class="value"><?= whDate($flight['actual_end_date'] ?? null) ?></div></div>
    <div class="field-group"><label>Менеджер</label><div class="value"><?= (int)($flight['assigned_manager_id'] ?? 0) > 0 ? 'ID ' . (int)$flight['assigned_manager_id'] : '—' ?></div></div>
    <div class="field-group"><label>Водитель</label><div class="value"><?= (int)($flight['driver_id'] ?? 0) > 0 ? 'ID ' . (int)$flight['driver_id'] : '—' ?></div></div>
    <div class="field-group"><label>Подрядчик</label><div class="value"><?= (int)($flight['contractor_id'] ?? 0) > 0 ? 'ID ' . (int)$flight['contractor_id'] : '—' ?></div></div>
    <div class="field-group"><label>Стоимость</label><div class="value"><?= round((float)($flight['cost'] ?? 0), 2) ?> ₽</div></div>
    <div class="field-group"><label>Комментарий</label><div class="value"><?= maxAdminHtml($flight['comment'] ?? '—') ?></div></div>
    <div class="field-group"><label>Block date</label><div class="value mono"><?= maxAdminHtml($flight['block_date'] ?? '—') ?></div></div>
    <div class="field-group"><label>Условия оплаты</label><div class="value"><?= maxAdminHtml($flight['payment_terms'] ?? '—') ?></div></div>
  </div>
</div>

<!-- ── RECLASSIFICATION FORM ──────────────────────────────────────────────── -->
<div class="grid">
  <div class="card">
    <div class="section-title">Изменить разметку</div>

    <!-- PREVIEW -->
    <form method="post" style="margin-bottom:12px">
      <input type="hidden" name="action" value="preview">
      <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

      <div class="field-group">
        <label>Маршрут груза <span style="color:#ef4444">*</span></label>
        <select name="new_route_type" id="pv_rt" onchange="whToggle('pv')" style="width:100%">
          <option value="">— Выберите —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>">ОО → Утилизатор</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>">ОО → Временный склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>">Склад → Склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>">Склад → Утилизатор</option>
        </select>
      </div>

      <div class="grid">
        <div class="field-group" id="pv_src_grp" style="display:none">
          <label>Склад отправления</label>
          <select name="new_source_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group" id="pv_dst_grp" style="display:none">
          <label>Склад назначения</label>
          <select name="new_destination_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <button class="btn primary" type="submit">Предпросмотр</button>
      <span class="small">Покажет ожидаемые складские движения</span>
    </form>

    <!-- APPLY -->
    <form method="post" onsubmit="return confirm('Применить изменения разметки? Это действие нельзя отменить.')">
      <input type="hidden" name="action" value="apply">
      <input type="hidden" name="flight_id" value="<?= (int)$flight['id'] ?>">

      <div class="field-group">
        <label>Маршрут груза <span style="color:#ef4444">*</span></label>
        <select name="new_route_type" id="ap_rt" onchange="whToggle('ap')" style="width:100%">
          <option value="">— Выберите —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>">ОО → Утилизатор</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>">ОО → Временный склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>">Склад → Склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>">Склад → Утилизатор</option>
        </select>
      </div>

      <div class="grid">
        <div class="field-group" id="ap_src_grp" style="display:none">
          <label>Склад отправления</label>
          <select name="new_source_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group" id="ap_dst_grp" style="display:none">
          <label>Склад назначения</label>
          <select name="new_destination_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div style="height:6px"></div>
      <div class="row">
        <label><input type="checkbox" name="confirm_apply" value="1" required> Подтверждаю изменение разметки рейса #<?= (int)$flight['id'] ?></label>
      </div>
      <div style="height:8px"></div>
      <button class="btn success" type="submit">Применить</button>
      <?php if ($isCompleted): ?>
      <span class="small">Для завершённых рейсов будут созданы складские движения</span>
      <?php else: ?>
      <span class="small">Рейс ещё не завершён. Складские движения будут созданы при завершении рейса.</span>
      <?php endif; ?>
    </form>
  </div>

  <!-- PREVIEW RESULT -->
  <?php if (is_array($preview) && $preview['total'] > 0): ?>
  <div class="card <?= $preview['already_exist'] > 0 ? 'warn-bg' : 'ok' ?>">
    <div class="section-title">Предпросмотр складских движений</div>
    <p class="small">
      Всего строк: <strong><?= $preview['total'] ?></strong> &nbsp;|&nbsp;
      Будет создано: <strong><?= $preview['will_create'] ?></strong> &nbsp;|&nbsp;
      Уже есть (пропуск): <strong><?= $preview['already_exist'] ?></strong>
    </p>
    <table>
      <thead><tr><th>Тип</th><th>Склад</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Статус</th></tr></thead>
      <tbody>
      <?php foreach ($preview['rows'] as $r): ?>
        <tr>
          <td><span class="badge badge-info"><?= $r['movement_type'] === 'receipt' ? 'Приход' : ($r['movement_type'] === 'issue' ? 'Расход' : ($r['movement_type'] === 'transfer_out' ? 'Отправка' : 'Приёмка')) ?></span></td>
          <td><?= maxAdminHtml($r['warehouse_name']) ?></td>
          <td class="mono"><?= (int)$r['zayavka_id'] ?></td>
          <td class="mono"><?= maxAdminHtml($r['fkko_code']) ?></td>
          <td><?= $r['mass_netto'] ?></td>
          <td><?= $r['mass_brutto'] ?></td>
          <td><?= $r['volume'] ?></td>
          <td><?= $r['exists'] ? '<span class="badge badge-warn">уже есть</span>' : '<span class="badge badge-ok">создать</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif (is_array($preview) && $preview['total'] === 0): ?>
  <div class="card warn-bg">Для выбранного типа маршрута складские движения не требуются.</div>
  <?php endif; ?>
</div>

<!-- ── WM RESULT AFTER APPLY ──────────────────────────────────────────────── -->
<?php if (is_array($wmResult)): ?>
<div class="card <?= $wmResult['success'] ? 'ok' : 'err' ?>">
  <div class="section-title">Результат создания складских движений</div>
  <p>Создано: <strong><?= $wmResult['created'] ?></strong> &nbsp;|&nbsp; Пропущено: <strong><?= $wmResult['skipped'] ?></strong></p>
  <?php if (!empty($wmResult['messages'])): ?>
    <p class="small"><?= maxAdminHtml(implode('; ', $wmResult['messages'])) ?></p>
  <?php endif; ?>
  <?php if (!empty($wmResult['errors'])): ?>
    <p class="small" style="color:#b42318"><?= maxAdminHtml(implode('; ', $wmResult['errors'])) ?></p>
  <?php endif; ?>
  <?php if (is_array($wmRowsAfter)): ?>
    <p class="small">Строк в warehouse_movements для рейса #<?= (int)$flight['id'] ?>: <strong><?= count($wmRowsAfter) ?></strong></p>
    <?php if (count($wmRowsAfter) > 0): ?>
    <table>
      <thead><tr><th>ID</th><th>Тип</th><th>Склад</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Дата</th></tr></thead>
      <tbody>
      <?php foreach ($wmRowsAfter as $wr): ?>
        <tr>
          <td class="mono"><?= (int)$wr['id'] ?></td>
          <td><?= $wr['movement_type'] === 'receipt' ? 'Приход' : ($wr['movement_type'] === 'issue' ? 'Расход' : ($wr['movement_type'] === 'transfer_out' ? 'Отправка' : 'Приёмка')) ?></td>
          <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($wr['warehouse_id'] ?? 0))) ?></td>
          <td class="mono"><?= (int)($wr['zayavka_id'] ?? 0) ?></td>
          <td class="mono"><?= maxAdminHtml($wr['fkko_code'] ?? '') ?></td>
          <td><?= $wr['mass_netto'] ?? '—' ?></td>
          <td><?= $wr['mass_brutto'] ?? '—' ?></td>
          <td><?= $wr['volume'] ?? '—' ?></td>
          <td><?= whDate($wr['movement_date'] ?? null) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // flight ?>

<?php endif; // authed ?>

</div><!-- .wrap -->

<script>
function whToggle(prefix) {
    var rt = document.getElementById(prefix + '_rt');
    var srcGrp = document.getElementById(prefix + '_src_grp');
    var dstGrp = document.getElementById(prefix + '_dst_grp');
    if (!rt || !srcGrp || !dstGrp) return;
    var v = rt.value;
    srcGrp.style.display = (v === 'warehouse_to_warehouse' || v === 'warehouse_to_utilizer') ? '' : 'none';
    dstGrp.style.display = (v === 'generator_to_warehouse' || v === 'warehouse_to_warehouse') ? '' : 'none';
}
</script>

</body>
</html>
