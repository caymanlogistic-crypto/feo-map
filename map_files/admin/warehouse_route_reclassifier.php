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

function whDateTime(?string $v): string {
    $v = trim((string)$v);
    if ($v === '' || $v === '0000-00-00 00:00:00') return '—';
    return date('d.m.Y H:i', strtotime($v));
}

function whWarehouseName(PDO $pdo, ?int $id): string {
    if (!$id || $id <= 0) return '—';
    try {
        $stmt = $pdo->prepare('SELECT name FROM warehouses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['name'] : "ID#$id";
    } catch (Throwable $e) {
        return "ID#$id";
    }
}

function whRouteTypeLabel(string $rt): string {
    $map = [
        ROUTE_TYPE_GENERATOR_TO_UTILIZER => 'ОО → Утилизатор',
        ROUTE_TYPE_GENERATOR_TO_WAREHOUSE => 'ОО → Склад',
        ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE => 'Склад → Склад',
        ROUTE_TYPE_WAREHOUSE_TO_UTILIZER => 'Склад → Утилизатор',
    ];
    return $map[$rt] ?? $rt;
}

function whMovementLabel(string $mt): string {
    $map = [
        'receipt' => 'Приход',
        'issue' => 'Расход',
        'transfer_out' => 'Перемещение (отправка)',
        'transfer_in' => 'Перемещение (приёмка)',
    ];
    return $map[$mt] ?? $mt;
}

function whLoadAllWarehouses(PDO $pdo): array {
    try {
        $stmt = $pdo->query('SELECT id, name FROM warehouses ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Preview expected movements WITHOUT creating them.
 * Returns array of expected rows with 'exists' flag.
 */
function whPreviewMovements(PDO $pdo, int $flightId, string $routeType, int $sourceWh, int $destWh): array {
    $preview = ['rows' => [], 'total' => 0, 'will_create' => 0, 'already_exist' => 0];

    $stmt = $pdo->prepare('SELECT id, status, zayavki_ids, actual_end_date FROM flights WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $flightId]);
    $flight = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($flight)) return $preview;

    $zayavkiIds = [];
    foreach (explode(',', (string)($flight['zayavki_ids'] ?? '')) as $id) {
        $id = (int)trim($id);
        if ($id > 0) $zayavkiIds[] = $id;
    }
    if (empty($zayavkiIds)) return $preview;

    $feoRows = [];
    try {
        $placeholders = implode(',', array_fill(0, count($zayavkiIds), '?'));
        $stmtFeo = $pdo->prepare("SELECT zayavka_id, naim_otkhoda_fkko, mass_netto, mass_brutto, summarnyy_obem FROM feo WHERE zayavka_id IN ({$placeholders})");
        $stmtFeo->execute($zayavkiIds);
        while ($row = $stmtFeo->fetch(PDO::FETCH_ASSOC)) {
            $feoRows[(int)$row['zayavka_id']] = $row;
        }
    } catch (Throwable $e) {
        return $preview;
    }

    $movementDate = !empty($flight['actual_end_date']) ? $flight['actual_end_date'] : date('Y-m-d');

    foreach ($zayavkiIds as $zid) {
        $feo = $feoRows[$zid] ?? null;

        $rows = [];
        if ($routeType === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE && $destWh > 0) {
            $rows[] = [WM_MOVEMENT_RECEIPT, $destWh, null, null];
        } elseif ($routeType === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER && $sourceWh > 0) {
            $rows[] = [WM_MOVEMENT_ISSUE, $sourceWh, null, null];
        } elseif ($routeType === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE && $sourceWh > 0 && $destWh > 0) {
            $rows[] = [WM_MOVEMENT_TRANSFER_OUT, $sourceWh, $sourceWh, $destWh];
            $rows[] = [WM_MOVEMENT_TRANSFER_IN, $destWh, $sourceWh, $destWh];
        }

        foreach ($rows as [$movType, $whId, $srcWh, $dstWh]) {
            $exists = wmMovementExists($pdo, $flightId, $movType, $whId, $zid);
            $entry = [
                'movement_type' => $movType,
                'warehouse_id' => $whId,
                'warehouse_name' => whWarehouseName($pdo, $whId),
                'source_warehouse_id' => $srcWh,
                'destination_warehouse_id' => $dstWh,
                'flight_id' => $flightId,
                'zayavka_id' => $zid,
                'fkko_code' => $feo ? (string)($feo['naim_otkhoda_fkko'] ?? '') : '',
                'mass_netto' => $feo ? (float)($feo['mass_netto'] ?? 0) : 0,
                'mass_brutto' => $feo ? (float)($feo['mass_brutto'] ?? 0) : 0,
                'volume' => $feo ? (float)($feo['summarnyy_obem'] ?? 0) : 0,
                'movement_date' => (string)$movementDate,
                'exists' => $exists,
            ];
            $preview['rows'][] = $entry;
            $preview['total']++;
            if ($exists) {
                $preview['already_exist']++;
            } else {
                $preview['will_create']++;
            }
        }
    }

    return $preview;
}

/**
 * Reclassify: update flight fields AND call createWarehouseMovementsForCompletedFlight.
 */
function whApplyReclassification(PDO $pdo, int $flightId, string $newRouteType, int $sourceWh, int $destWh, array $expectedOld): array {
    // 1. Load current flight
    $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $flightId]);
    $flight = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($flight)) {
        return ['success' => false, 'error' => 'Рейс не найден'];
    }

    // 2. Check expected_old_values
    $checks = [
        'route_type' => (string)($flight['route_type'] ?? ''),
        'source_warehouse_id' => (int)($flight['source_warehouse_id'] ?? 0),
        'destination_warehouse_id' => (int)($flight['destination_warehouse_id'] ?? 0),
        'status' => (string)($flight['status'] ?? ''),
    ];
    foreach ($expectedOld as $k => $v) {
        if (!array_key_exists($k, $checks)) continue;
        $actual = is_int($checks[$k]) ? (int)$v : (string)$v;
        if ((string)$checks[$k] !== (string)$actual) {
            return [
                'success' => false,
                'error' => "Контрольное значение изменилось: поле '{$k}' ожидалось '{$actual}', получено '{$checks[$k]}'. Обновите страницу.",
            ];
        }
    }

    // 3. Update flight fields
    $unloadType = resolveUnloadTypeByRouteType($newRouteType);
    try {
        $stmtUpd = $pdo->prepare('UPDATE flights SET route_type = :rt, unload_type = :ut, source_warehouse_id = :sw, destination_warehouse_id = :dw, updated_at = NOW() WHERE id = :id');
        $stmtUpd->execute([
            ':rt' => $newRouteType,
            ':ut' => $unloadType,
            ':sw' => $sourceWh > 0 ? $sourceWh : null,
            ':dw' => $destWh > 0 ? $destWh : null,
            ':id' => $flightId,
        ]);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Ошибка обновления рейса: ' . $e->getMessage()];
    }

    // 4. Call warehouse movements service
    $wmResult = createWarehouseMovementsForCompletedFlight($pdo, $flightId);

    return [
        'success' => $wmResult['success'],
        'error' => !$wmResult['success'] ? implode('; ', $wmResult['errors']) : null,
        'created' => $wmResult['created'],
        'skipped' => $wmResult['skipped'],
        'messages' => $wmResult['messages'],
    ];
}

// ── State ────────────────────────────────────────────────────────────────────

$flash = '';
$flashType = 'ok';
$searchResults = [];
$selectedFlight = null;
$previewResult = null;
$applyResult = null;
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

    // ── SEARCH ────────────────────────────────────────────────────────────
    if ($action === 'search') {
        $idFrom = (int)maxAdminPost('id_from', '0');
        $idTo = (int)maxAdminPost('id_to', '0');
        $dateFrom = trim((string)maxAdminPost('date_from', ''));
        $dateTo = trim((string)maxAdminPost('date_to', ''));
        $filterRouteType = (string)maxAdminPost('filter_route_type', '');
        $filterWarehouse = (int)maxAdminPost('filter_warehouse', '0');

        $where = ["f.status = 'completed'"];
        $params = [];

        if ($idFrom > 0) {
            $where[] = 'f.id >= :id_from';
            $params[':id_from'] = $idFrom;
        }
        if ($idTo > 0) {
            $where[] = 'f.id <= :id_to';
            $params[':id_to'] = $idTo;
        }
        if ($dateFrom !== '') {
            $where[] = 'f.actual_end_date >= :date_from';
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'f.actual_end_date <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
        if ($filterRouteType !== '') {
            $where[] = 'f.route_type = :filter_route_type';
            $params[':filter_route_type'] = $filterRouteType;
        }
        if ($filterWarehouse > 0) {
            $where[] = '(f.source_warehouse_id = :wh OR f.destination_warehouse_id = :wh2)';
            $params[':wh'] = $filterWarehouse;
            $params[':wh2'] = $filterWarehouse;
        }

        $sql = 'SELECT f.id, f.status, f.route_type, f.unload_type, f.source_warehouse_id, f.destination_warehouse_id,
                       f.zayavki_ids, f.zayavki_count, f.actual_start_date, f.actual_end_date, f.comment,
                       f.planned_start_date, f.planned_end_date, f.manager_id
                FROM flights f
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY f.id DESC
                LIMIT 100';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $flash = 'Ошибка поиска: ' . $e->getMessage();
            $flashType = 'err';
        }
    }

    // ── SELECT FLIGHT ─────────────────────────────────────────────────────
    elseif ($action === 'select') {
        $flightId = (int)maxAdminPost('flight_id', '0');
        if ($flightId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $selectedFlight = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($selectedFlight)) {
                    $flash = 'Рейс #' . $flightId . ' не найден';
                    $flashType = 'err';
                }
            } catch (Throwable $e) {
                $flash = 'Ошибка загрузки: ' . $e->getMessage();
                $flashType = 'err';
            }
        }
    }

    // ── PREVIEW ───────────────────────────────────────────────────────────
    elseif ($action === 'preview') {
        $flightId = (int)maxAdminPost('preview_flight_id', '0');
        $newRouteType = (string)maxAdminPost('new_route_type', '');
        $sourceWh = (int)maxAdminPost('new_source_warehouse', '0');
        $destWh = (int)maxAdminPost('new_destination_warehouse', '0');

        if ($flightId <= 0) {
            $flash = 'Укажите ID рейса';
            $flashType = 'err';
        } elseif ($newRouteType === '') {
            $flash = 'Выберите новый тип маршрута';
            $flashType = 'err';
        } else {
            // Reload flight for display
            $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $flightId]);
            $selectedFlight = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($selectedFlight)) {
                $flash = 'Рейс #' . $flightId . ' не найден';
                $flashType = 'err';
            } else {
                $previewResult = whPreviewMovements($pdo, $flightId, $newRouteType, $sourceWh, $destWh);
                if ($previewResult['total'] === 0 && $newRouteType !== ROUTE_TYPE_GENERATOR_TO_UTILIZER) {
                    $flash = 'Предпросмотр: для данного route_type движения не требуются, либо нет заявок/данных ФЭО.';
                    $flashType = 'ok';
                }
            }
        }
    }

    // ── APPLY ─────────────────────────────────────────────────────────────
    elseif ($action === 'apply') {
        $flightId = (int)maxAdminPost('apply_flight_id', '0');
        $newRouteType = (string)maxAdminPost('apply_route_type', '');
        $sourceWh = (int)maxAdminPost('apply_source_warehouse', '0');
        $destWh = (int)maxAdminPost('apply_destination_warehouse', '0');
        $confirmed = maxAdminPost('confirm_reclassify', '0') === '1';
        $expectedRouteType = (string)maxAdminPost('expected_route_type', '');
        $expectedSourceWh = (int)maxAdminPost('expected_source_warehouse', '0');
        $expectedDestWh = (int)maxAdminPost('expected_destination_warehouse', '0');
        $expectedStatus = (string)maxAdminPost('expected_status', '');

        if (!$confirmed) {
            $flash = 'Подтвердите операцию';
            $flashType = 'err';
        } elseif ($flightId <= 0 || $newRouteType === '') {
            $flash = 'Заполните обязательные поля';
            $flashType = 'err';
        } else {
            $expectedOld = [
                'route_type' => $expectedRouteType,
                'source_warehouse_id' => $expectedSourceWh,
                'destination_warehouse_id' => $expectedDestWh,
                'status' => $expectedStatus,
            ];
            $applyResult = whApplyReclassification($pdo, $flightId, $newRouteType, $sourceWh, $destWh, $expectedOld);
            if ($applyResult['success']) {
                $flash = "Рейс #{$flightId} переквалифицирован. Движений создано: {$applyResult['created']}, пропущено (дубли): {$applyResult['skipped']}.";
                // Reload flight
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $selectedFlight = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $flash = 'Ошибка: ' . ($applyResult['error'] ?? 'Неизвестная ошибка');
                $flashType = 'err';
                // Reload flight
                $stmt = $pdo->prepare('SELECT * FROM flights WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $flightId]);
                $selectedFlight = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }
}

// ── Auto-select if single result ─────────────────────────────────────────────
if (count($searchResults) === 1 && !$selectedFlight && !$applyResult) {
    $selectedFlight = $searchResults[0];
}

?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Переквалификация складских маршрутов</title>
<style>
body{margin:0;background:#f4f6f8;color:#1e293b;font-family:Arial,sans-serif}
.wrap{max-width:1440px;margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.linkbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:14px;margin-bottom:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155;white-space:nowrap}
input[type=text],input[type=date],input[type=number],select,textarea{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;background:#fff;color:#1e293b}
input[type=text],input[type=number]{width:120px}
select{max-width:280px}
.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center;text-decoration:none;color:#1e293b}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}
.btn.warn{background:#f59e0b;color:#fff;border-color:#d97706}
.btn.danger{background:#fdf2f2;border-color:#f1b3b3;color:#b42318}
.btn.success{background:#10b981;color:#fff;border-color:#059669}
.btn:disabled{opacity:.5;cursor:not-allowed}
.small{font-size:12px;color:#64748b}
.ok{background:#ecfdf3;border-color:#b7e4c7;color:#0f766e}
.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
.warn-bg{background:#fffbeb;border-color:#fde68a;color:#92400e}
table{width:100%;border-collapse:collapse}
th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f8fafc;color:#475569;font-weight:600}
tr:hover{background:#f8fafc}
.mono{font-family:Consolas,monospace;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge-ok{background:#dcfce7;color:#166534}
.badge-warn{background:#fef3c7;color:#92400e}
.badge-err{background:#fee2e2;color:#991b1b}
.badge-info{background:#dbeafe;color:#1e40af}
.hint{font-size:12px;color:#334155;background:#f8fafc;border-left:3px solid #0ea5b7;padding:8px 10px;border-radius:4px;margin:6px 0}
.field-group{margin-bottom:8px}
.field-group label{display:block;margin-bottom:2px;font-weight:600;font-size:12px;color:#64748b}
.field-group .value{font-size:14px}
.login{max-width:420px;margin:80px auto}
.preview-table td{padding:4px 6px}
.section-title{font-size:15px;font-weight:600;color:#0f172a;margin:0 0 8px 0;padding-bottom:4px;border-bottom:2px solid #0ea5b7}
@media(max-width:1100px){.grid,.grid-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">

<div class="top">
  <div class="linkbar">
    <h2 style="margin:0;font-size:18px">Переквалификация складских маршрутов</h2>
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

<!-- ── FILTERS ──────────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="section-title">Поиск завершённых рейсов</div>
  <form method="post">
    <input type="hidden" name="action" value="search">
    <div class="grid-3">
      <div class="field-group">
        <label>ID рейса (от)</label>
        <input type="number" name="id_from" value="<?= maxAdminHtml(maxAdminPost('id_from', '')) ?>" placeholder="например 100" style="width:100%">
      </div>
      <div class="field-group">
        <label>ID рейса (до)</label>
        <input type="number" name="id_to" value="<?= maxAdminHtml(maxAdminPost('id_to', '')) ?>" placeholder="например 200" style="width:100%">
      </div>
      <div class="field-group">
        <label>Текущий тип маршрута</label>
        <select name="filter_route_type" style="width:100%">
          <option value="">— Все типы —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>" <?= maxAdminPost('filter_route_type') === ROUTE_TYPE_GENERATOR_TO_UTILIZER ? 'selected' : '' ?>>ОО → Утилизатор</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>" <?= maxAdminPost('filter_route_type') === ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ? 'selected' : '' ?>>ОО → Склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>" <?= maxAdminPost('filter_route_type') === ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ? 'selected' : '' ?>>Склад → Склад</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>" <?= maxAdminPost('filter_route_type') === ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ? 'selected' : '' ?>>Склад → Утилизатор</option>
        </select>
      </div>
      <div class="field-group">
        <label>Дата завершения (от)</label>
        <input type="date" name="date_from" value="<?= maxAdminHtml(maxAdminPost('date_from', '')) ?>" style="width:100%">
      </div>
      <div class="field-group">
        <label>Дата завершения (до)</label>
        <input type="date" name="date_to" value="<?= maxAdminHtml(maxAdminPost('date_to', '')) ?>" style="width:100%">
      </div>
      <div class="field-group">
        <label>Склад (отправления или назначения)</label>
        <select name="filter_warehouse" style="width:100%">
          <option value="0">— Все склады —</option>
          <?php foreach ($allWarehouses as $wh): ?>
          <option value="<?= (int)$wh['id'] ?>" <?= (int)maxAdminPost('filter_warehouse', '0') === (int)$wh['id'] ? 'selected' : '' ?>><?= maxAdminHtml($wh['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="height:8px"></div>
    <button class="btn primary" type="submit">Найти</button>
    <span class="small">Показывает завершённые рейсы (статус «Груз сдан»), макс. 100</span>
  </form>
</div>

<!-- ── SEARCH RESULTS ───────────────────────────────────────────────────────── -->
<?php if (!empty($searchResults)): ?>
<div class="card">
  <div class="section-title">Результаты поиска (<?= count($searchResults) ?>)</div>
  <div style="max-height:400px;overflow:auto">
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Дата зав.</th>
        <th>Заявки</th>
        <th>Тип маршрута</th>
        <th>Склад отпр.</th>
        <th>Склад назн.</th>
        <th>Действие</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($searchResults as $row): ?>
      <tr>
        <td class="mono">#<?= (int)$row['id'] ?></td>
        <td><?= whDate($row['actual_end_date'] ?? null) ?></td>
        <td class="mono"><?= maxAdminHtml($row['zayavki_ids'] ?? '') ?> (<?= (int)($row['zayavka_count'] ?? 0) ?>)</td>
        <td><?= whRouteTypeLabel((string)($row['route_type'] ?? '')) ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($row['source_warehouse_id'] ?? 0))) ?></td>
        <td><?= maxAdminHtml(whWarehouseName($pdo, (int)($row['destination_warehouse_id'] ?? 0))) ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="select">
            <input type="hidden" name="flight_id" value="<?= (int)$row['id'] ?>">
            <button class="btn" type="submit">Выбрать</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && maxAdminPost('action') === 'search'): ?>
<div class="card warn-bg">Ничего не найдено. Попробуйте изменить фильтры.</div>
<?php endif; ?>

<!-- ── FLIGHT CARD + RECLASSIFICATION ──────────────────────────────────────── -->
<?php if (is_array($selectedFlight)): ?>
<?php
  $f = $selectedFlight;
  $currentRt = (string)($f['route_type'] ?? '');
  $currentSrc = (int)($f['source_warehouse_id'] ?? 0);
  $currentDst = (int)($f['destination_warehouse_id'] ?? 0);
  $currentStatus = (string)($f['status'] ?? '');

  // Count existing WM
  $wmCount = 0;
  try {
      $stmtWm = $pdo->prepare('SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = :fid');
      $stmtWm->execute([':fid' => (int)$f['id']]);
      $wmCount = (int)$stmtWm->fetchColumn();
  } catch (Throwable $e) {}
?>
<div class="grid">
  <!-- Flight info card -->
  <div class="card">
    <div class="section-title">Карточка рейса #<?= (int)$f['id'] ?></div>
    <div class="grid">
      <div class="field-group">
        <label>Статус</label>
        <div class="value"><span class="badge badge-<?= $currentStatus === 'completed' ? 'ok' : 'warn' ?>"><?= maxAdminHtml($currentStatus) ?></span></div>
      </div>
      <div class="field-group">
        <label>Текущий тип маршрута</label>
        <div class="value"><span class="badge badge-info"><?= whRouteTypeLabel($currentRt) ?></span></div>
      </div>
      <div class="field-group">
        <label>Склад отправления</label>
        <div class="value"><?= maxAdminHtml(whWarehouseName($pdo, $currentSrc)) ?></div>
      </div>
      <div class="field-group">
        <label>Склад назначения</label>
        <div class="value"><?= maxAdminHtml(whWarehouseName($pdo, $currentDst)) ?></div>
      </div>
      <div class="field-group">
        <label>Дата завершения</label>
        <div class="value"><?= whDateTime($f['actual_end_date'] ?? null) ?></div>
      </div>
      <div class="field-group">
        <label>Дата начала</label>
        <div class="value"><?= whDateTime($f['actual_start_date'] ?? null) ?></div>
      </div>
      <div class="field-group">
        <label>Заявки</label>
        <div class="value mono"><?= maxAdminHtml($f['zayavki_ids'] ?? '') ?> (<?= (int)($f['zayavka_count'] ?? 0) ?> шт.)</div>
      </div>
      <div class="field-group">
        <label>Движений в WM</label>
        <div class="value"><span class="badge badge-<?= $wmCount > 0 ? 'ok' : 'warn' ?>"><?= $wmCount ?> строк</span></div>
      </div>
      <div class="field-group">
        <label>Комментарий</label>
        <div class="value"><?= maxAdminHtml($f['comment'] ?? '—') ?></div>
      </div>
      <div class="field-group">
        <label>unload_type</label>
        <div class="value mono"><?= maxAdminHtml($f['unload_type'] ?? '—') ?></div>
      </div>
    </div>
  </div>

  <!-- Reclassification form -->
  <div class="card">
    <div class="section-title">Переквалификация</div>

    <?php if ($currentStatus !== 'completed'): ?>
    <div class="hint" style="border-left-color:#f59e0b">
      <strong>Внимание:</strong> рейс в статусе «<?= maxAdminHtml($currentStatus) ?>». Переквалификация рекомендуется только для завершённых рейсов (completed).
    </div>
    <?php endif; ?>

    <!-- PREVIEW form -->
    <form method="post" style="margin-bottom:12px">
      <input type="hidden" name="action" value="preview">
      <input type="hidden" name="preview_flight_id" value="<?= (int)$f['id'] ?>">

      <div class="field-group">
        <label>Новый тип маршрута <span style="color:#ef4444">*</span></label>
        <select name="new_route_type" id="preview_route_type" onchange="whToggleFields('preview')" style="width:100%">
          <option value="">— Выберите тип —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>">ОО → Утилизатор (без движений)</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>">ОО → Склад (приход)</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>">Склад → Склад (перемещение)</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>">Склад → Утилизатор (расход)</option>
        </select>
      </div>

      <div class="grid">
        <div class="field-group" id="preview_source_group" style="display:none">
          <label>Склад отправления</label>
          <select name="new_source_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group" id="preview_dest_group" style="display:none">
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
      <button class="btn primary" type="submit">Предпросмотр движений</button>
      <span class="small">Без сохранения — только расчёт</span>
    </form>

    <!-- APPLY form -->
    <form method="post" onsubmit="return confirm('Вы уверены? Будут созданы складские движения и обновлён тип маршрута. Это действие нельзя отменить.')">
      <input type="hidden" name="action" value="apply">
      <input type="hidden" name="apply_flight_id" value="<?= (int)$f['id'] ?>">

      <div class="field-group">
        <label>Новый тип маршрута <span style="color:#ef4444">*</span></label>
        <select name="apply_route_type" id="apply_route_type" onchange="whToggleFields('apply')" style="width:100%">
          <option value="">— Выберите тип —</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_UTILIZER ?>">ОО → Утилизатор (без движений)</option>
          <option value="<?= ROUTE_TYPE_GENERATOR_TO_WAREHOUSE ?>">ОО → Склад (приход)</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_WAREHOUSE ?>">Склад → Склад (перемещение)</option>
          <option value="<?= ROUTE_TYPE_WAREHOUSE_TO_UTILIZER ?>">Склад → Утилизатор (расход)</option>
        </select>
      </div>

      <div class="grid">
        <div class="field-group" id="apply_source_group" style="display:none">
          <label>Склад отправления</label>
          <select name="apply_source_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group" id="apply_dest_group" style="display:none">
          <label>Склад назначения</label>
          <select name="apply_destination_warehouse" style="width:100%">
            <option value="0">— Не выбран —</option>
            <?php foreach ($allWarehouses as $wh): ?>
            <option value="<?= (int)$wh['id'] ?>"><?= maxAdminHtml($wh['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Expected old values for safety check -->
      <input type="hidden" name="expected_route_type" value="<?= maxAdminHtml($currentRt) ?>">
      <input type="hidden" name="expected_source_warehouse" value="<?= $currentSrc ?>">
      <input type="hidden" name="expected_destination_warehouse" value="<?= $currentDst ?>">
      <input type="hidden" name="expected_status" value="<?= maxAdminHtml($currentStatus) ?>">

      <div style="height:6px"></div>
      <div class="row">
        <label><input type="checkbox" name="confirm_reclassify" value="1" required> Подтверждаю переквалификацию рейса #<?= (int)$f['id'] ?></label>
      </div>
      <div style="height:8px"></div>
      <button class="btn success" type="submit">Применить переквалификацию</button>
      <span class="small">Создаст движения и обновит рейс</span>
    </form>
  </div>
</div>

<!-- ── PREVIEW RESULT ────────────────────────────────────────────────────── -->
<?php if (is_array($previewResult) && !empty($previewResult['rows'])): ?>
<div class="card <?= $previewResult['already_exist'] > 0 ? 'warn-bg' : 'ok' ?>">
  <div class="section-title">Предпросмотр движений</div>
  <p class="small">
    Всего строк: <strong><?= $previewResult['total'] ?></strong> &nbsp;|&nbsp;
    Будет создано: <strong><?= $previewResult['will_create'] ?></strong> &nbsp;|&nbsp;
    Уже существуют (пропуск): <strong><?= $previewResult['already_exist'] ?></strong>
  </p>
  <div style="max-height:300px;overflow:auto">
  <table class="preview-table">
    <thead>
      <tr>
        <th>Тип</th>
        <th>Склад</th>
        <th>Заявка</th>
        <th>ФККО</th>
        <th>Нетто, т</th>
        <th>Брутто, т</th>
        <th>Объём, м³</th>
        <th>Дата</th>
        <th>Статус</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($previewResult['rows'] as $row): ?>
      <tr>
        <td><span class="badge badge-info"><?= whMovementLabel($row['movement_type']) ?></span></td>
        <td><?= maxAdminHtml($row['warehouse_name']) ?></td>
        <td class="mono"><?= (int)$row['zayavka_id'] ?></td>
        <td class="mono"><?= maxAdminHtml($row['fkko_code']) ?></td>
        <td><?= number_format($row['mass_netto'], 3, '.', ' ') ?></td>
        <td><?= number_format($row['mass_brutto'], 3, '.', ' ') ?></td>
        <td><?= number_format($row['volume'], 3, '.', ' ') ?></td>
        <td><?= whDate($row['movement_date']) ?></td>
        <td><?php if ($row['exists']): ?><span class="badge badge-warn">∃ уже есть</span><?php else: ?><span class="badge badge-ok">✓ создать</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php endif; // selectedFlight ?>

<?php endif; // authed ?>

</div><!-- .wrap -->

<script>
function whToggleFields(prefix) {
    var rt = document.getElementById(prefix + '_route_type');
    var srcGrp = document.getElementById(prefix + '_source_group');
    var dstGrp = document.getElementById(prefix + '_dest_group');
    if (!rt || !srcGrp || !dstGrp) return;
    var val = rt.value;
    // source: warehouse_to_warehouse, warehouse_to_utilizer
    // dest: generator_to_warehouse, warehouse_to_warehouse
    srcGrp.style.display = (val === 'warehouse_to_warehouse' || val === 'warehouse_to_utilizer') ? '' : 'none';
    dstGrp.style.display = (val === 'generator_to_warehouse' || val === 'warehouse_to_warehouse') ? '' : 'none';
}
</script>

</body>
</html>
