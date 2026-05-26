<?php
// CLI-only acceptance test for 4 route types
// Uses штатные PHP functions (same logic as save_planned_route.php)
// Creates only TEST ACCEPT flights, never touches production records
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

// --- helpers ---
function dbAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function dbExec($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);return $s&&$s->execute($params);}
function qi($s){return '`'.str_replace('`','``',$s).'`';}

// --- status constants (same as save_planned_route.php) ---
define('S_PLANNED', 'planned_route');
define('S_FOUND',   'found');
define('S_STARTED', 'started');
define('S_COMPLETED', 'completed');
define('RT_G2U', 'generator_to_utilizer');
define('RT_G2W', 'generator_to_warehouse');
define('RT_W2W', 'warehouse_to_warehouse');
define('RT_W2U', 'warehouse_to_utilizer');

// --- copy of assertTransitionAllowed from save_planned_route.php ---
function assertTransitionAllowed(array $flight, string $target): ?string {
    $cur = (string)($flight['status'] ?? '');
    if ($cur === S_PLANNED   && $target === S_FOUND)     return null;
    if ($cur === S_FOUND     && $target === S_STARTED)   return null;
    if ($cur === S_FOUND     && $target === S_PLANNED)   return null;
    if ($cur === S_STARTED   && $target === S_FOUND)     return null;
    if ($cur === S_STARTED   && $target === S_COMPLETED) return null;
    return 'Invalid transition';
}

// --- штатный transition (same logic as inside save_planned_route.php) ---
function transitionRouteStatus(PDO $pdo, int $flightId, string $targetStatus, ?string $dateValue = null): array {
    $rows = dbAll($pdo, 'SELECT id, status, route_type, unload_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, driver_id, planned_start_date_from, planned_start_date_to, cost, comment FROM flights WHERE id = :id', [':id' => $flightId]);
    if (empty($rows)) return ['success' => false, 'message' => 'Flight not found'];
    $flight = $rows[0];
    $deny = assertTransitionAllowed($flight, $targetStatus);
    if ($deny !== null) return ['success' => false, 'message' => $deny];
    $statusBefore = (string)$flight['status'];

    if ($targetStatus === S_FOUND && $statusBefore === S_PLANNED) {
        dbExec($pdo, 'UPDATE flights SET status = :s WHERE id = :id LIMIT 1', [':s' => S_FOUND, ':id' => $flightId]);
    } elseif ($targetStatus === S_STARTED) {
        $ts = strtotime($dateValue ?? date('Y-m-d H:i:s'));
        if ($ts === false) return ['success' => false, 'message' => 'Invalid date'];
        dbExec($pdo, 'UPDATE flights SET status = :s, actual_start_date = :d WHERE id = :id LIMIT 1',
            [':s' => S_STARTED, ':d' => date('Y-m-d H:i:s', $ts), ':id' => $flightId]);
    } elseif ($targetStatus === S_FOUND && $statusBefore === S_STARTED) {
        dbExec($pdo, 'UPDATE flights SET status = :s, actual_start_date = NULL WHERE id = :id LIMIT 1',
            [':s' => S_FOUND, ':id' => $flightId]);
    } elseif ($targetStatus === S_COMPLETED) {
        $ts = strtotime($dateValue ?? date('Y-m-d H:i:s'));
        if ($ts === false) return ['success' => false, 'message' => 'Invalid date'];
        dbExec($pdo, 'UPDATE flights SET status = :s, actual_end_date = :d WHERE id = :id LIMIT 1',
            [':s' => S_COMPLETED, ':d' => date('Y-m-d H:i:s', $ts), ':id' => $flightId]);
        createWarehouseMovementsForCompletedFlight($pdo, $flightId);
    } elseif ($targetStatus === S_PLANNED) {
        dbExec($pdo, 'UPDATE flights SET status = :s WHERE id = :id LIMIT 1', [':s' => S_PLANNED, ':id' => $flightId]);
    } else {
        return ['success' => false, 'message' => 'Unsupported transition'];
    }
    return ['success' => true, 'message' => "OK: {$statusBefore} -> {$targetStatus}"];
}

// --- resolve manager column ---
function resolveManagerColumn(PDO $pdo): string {
    $cols = dbAll($pdo, 'SHOW COLUMNS FROM flights');
    $map = []; foreach($cols as $c) $map[(string)($c['Field']??'')] = true;
    foreach(['assigned_manager_id','manager_id'] as $c) if(isset($map[$c])) return $c;
    return 'assigned_manager_id';
}

// ===== MAIN =====
echo "=== ACCEPTANCE TEST (штатные PHP функции) ===\n\n";

$mcid = resolveManagerColumn($pdo);
$qm = qi($mcid);

// --- resources ---
$mgrs = dbAll($pdo, 'SELECT id FROM users LIMIT 1');
$mgr = !empty($mgrs) ? (int)$mgrs[0]['id'] : 0;
echo "Manager: $mgr\n";
if ($mgr <= 0) { echo "FAIL: No manager found in users table.\n"; exit(1); }

$zids = [];
foreach (dbAll($pdo, 'SELECT zayavka_id FROM feo ORDER BY zayavka_id DESC LIMIT 3') as $r) {
    $zids[] = (string)$r['zayavka_id'];
}
echo "Zayavki: " . implode(',', $zids) . "\n";
if (count($zids) < 2) { echo "FAIL: Need at least 2 zayavka_id in feo table.\n"; exit(1); }

$whs = dbAll($pdo, 'SELECT id FROM warehouses WHERE id > 0 ORDER BY id ASC LIMIT 2');
$wh1 = !empty($whs) ? (int)$whs[0]['id'] : 0;
$wh2 = count($whs) > 1 ? (int)$whs[1]['id'] : 0;
echo "WH1=$wh1 WH2=$wh2\n";
if ($wh1 <= 0) { echo "FAIL: No warehouse found.\n"; exit(1); }

// --- test driver ---
$dr = dbAll($pdo, "SELECT id FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%TECT%' LIMIT 1");
$drv = !empty($dr) ? (int)$dr[0]['id'] : 0;
if ($drv <= 0) {
    dbExec($pdo, "INSERT INTO drivers (full_name,vehicle_make_plate) VALUES (:n,:p)", [':n' => 'TEST TECT Acc Driver', ':p' => 'T999TEST']);
    $drv = (int)$pdo->lastInsertId();
}
echo "Driver: $drv\n";
if ($drv <= 0) { echo "FAIL: Cannot create test driver.\n"; exit(1); }

// --- clean previous TEST ACCEPT flights ---
$prev = dbAll($pdo, "SELECT id FROM flights WHERE comment LIKE 'TEST ACCEPT%'");
echo "Deleting " . count($prev) . " previous TEST ACCEPT flights\n";
foreach ($prev as $p) {
    dbExec($pdo, 'DELETE FROM flights WHERE id = ' . (int)$p['id'] . ' AND comment LIKE \'TEST ACCEPT%\'');
}

// --- 4 test route definitions ---
$tests = [
    ['name' => 'TEST ACCEPT generator_to_utilizer',  'rt' => RT_G2U, 'sw' => null,   'dw' => null],
    ['name' => 'TEST ACCEPT generator_to_warehouse',  'rt' => RT_G2W, 'sw' => null,   'dw' => $wh1],
    ['name' => 'TEST ACCEPT warehouse_to_warehouse',  'rt' => RT_W2W, 'sw' => $wh1,   'dw' => $wh2],
    ['name' => 'TEST ACCEPT warehouse_to_utilizer',   'rt' => RT_W2U, 'sw' => $wh1,   'dw' => null],
];

$flightIds = [];
$expectedCount = count($tests);

echo "\n=== CREATE FLIGHTS ===\n";
foreach ($tests as $i => $t) {
    $rt  = $t['rt'];
    $ult = ($rt === RT_G2U || $rt === RT_W2U) ? 'OO' : 'SKLAD';
    $sw  = ($t['sw'] > 0) ? $t['sw'] : null;
    $dw  = ($t['dw'] > 0) ? $t['dw'] : null;
    $zlist = array_slice($zids, 0, 2);

    $sql = "INSERT INTO flights (status, comment, cost, unload_type, route_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, zayavki_count, {$qm}, planned_start_date_from, planned_start_date_to, driver_id, block_date)
            VALUES ('planned_route', :c, 1000, :ut, :rt, :sw, :dw, :zs, :zc, :mgr, :pf, :pt, :drv, NOW())";
    $params = [
        ':c'   => $t['name'],
        ':ut'  => $ult,
        ':rt'  => $rt,
        ':sw'  => $sw,
        ':dw'  => $dw,
        ':zs'  => implode(',', $zlist),
        ':zc'  => count($zlist),
        ':mgr' => $mgr,
        ':pf'  => date('Y-m-d H:i:s', strtotime('+2 days')),
        ':pt'  => date('Y-m-d H:i:s', strtotime('+3 days')),
        ':drv' => ($i === 0 ? $drv : 0),
    ];

    $ok = dbExec($pdo, $sql, $params);
    $nid = (int)$pdo->lastInsertId();

    if (!$ok || $nid <= 0) {
        $errInfo = $pdo->errorInfo();
        echo "FAILED create {$t['name']}: PDO error " . implode(' | ', $errInfo) . "\n";
        echo "FAIL: Created only " . count($flightIds) . "/{$expectedCount} flights. Stopping.\n";
        exit(1);
    }
    $flightIds[] = $nid;
    echo "Created #{$nid}: {$t['name']} (rt={$rt}, ut={$ult}, sw=" . ($sw ?? 'NULL') . ", dw=" . ($dw ?? 'NULL') . ")\n";
}

if (count($flightIds) !== $expectedCount) {
    echo "FAIL: Created " . count($flightIds) . "/{$expectedCount} flights. Stopping.\n";
    exit(1);
}

// --- SELECT after create ---
echo "\n=== SELECT AFTER CREATE ===\n";
$flightCols = ['id','status','route_type','unload_type','source_warehouse_id','destination_warehouse_id','zayavki_ids','zayavki_count','actual_start_date','actual_end_date','driver_id','cost','comment'];
foreach ($flightIds as $fid) {
    $r = dbAll($pdo, "SELECT " . implode(',', array_map('qi', $flightCols)) . " FROM flights WHERE id = {$fid}");
    if (!$r) { echo "#{$fid} NOT FOUND\n"; continue; }
    $x = $r[0];
    echo "#{$fid} status={$x['status']} rt={$x['route_type']} ut={$x['unload_type']} sw={$x['source_warehouse_id']} dw={$x['destination_warehouse_id']} zids={$x['zayavki_ids']} zcnt={$x['zayavki_count']} astart=" . ($x['actual_start_date'] ?? 'NULL') . " aend=" . ($x['actual_end_date'] ?? 'NULL') . " drv={$x['driver_id']} cost={$x['cost']} comment={$x['comment']}\n";
}

// --- transitions ---
echo "\n=== TRANSITIONS ===\n";
foreach ($flightIds as $fid) {
    $res = transitionRouteStatus($pdo, $fid, S_FOUND);
    echo "#{$fid} planned->found: success={$res['success']} {$res['message']}\n";

    $sd = date('Y-m-d H:i:s', strtotime('-1 hour'));
    $res = transitionRouteStatus($pdo, $fid, S_STARTED, $sd);
    echo "#{$fid} found->started: success={$res['success']} {$res['message']}\n";

    $res = transitionRouteStatus($pdo, $fid, S_FOUND); // rollback
    echo "#{$fid} started->found ROLLBACK: success={$res['success']} {$res['message']}\n";

    $sd2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
    $res = transitionRouteStatus($pdo, $fid, S_STARTED, $sd2);
    echo "#{$fid} found->started: success={$res['success']} {$res['message']}\n";

    $ed = date('Y-m-d H:i:s');
    $res = transitionRouteStatus($pdo, $fid, S_COMPLETED, $ed);
    echo "#{$fid} COMPLETED: success={$res['success']} {$res['message']}\n";
}

// --- final SELECT flights ---
echo "\n=== FINAL FLIGHT STATE ===\n";
foreach ($flightIds as $fid) {
    $r = dbAll($pdo, "SELECT " . implode(',', array_map('qi', $flightCols)) . " FROM flights WHERE id = {$fid}");
    if (!$r) continue;
    $x = $r[0];
    echo "#{$fid} status={$x['status']} rt={$x['route_type']} ut={$x['unload_type']} sw={$x['source_warehouse_id']} dw={$x['destination_warehouse_id']} zids={$x['zayavki_ids']} zcnt={$x['zayavki_count']} astart=" . ($x['actual_start_date'] ?? 'NULL') . " aend=" . ($x['actual_end_date'] ?? 'NULL') . " drv={$x['driver_id']} cost={$x['cost']}\n";
}

// --- warehouse movements ---
echo "\n=== WAREHOUSE MOVEMENTS ===\n";
if (empty($flightIds)) {
    echo "FAIL: No test flights created, stopping before SELECT.\n";
    exit(1);
}
$idlist = implode(',', $flightIds);
$wmCols = ['id','movement_type','warehouse_id','source_warehouse_id','destination_warehouse_id','flight_id','zayavka_id','fkko_code','mass_netto','mass_brutto','volume','status','comment'];
$wms = dbAll($pdo, "SELECT " . implode(',', array_map('qi', $wmCols)) . " FROM warehouse_movements WHERE flight_id IN ({$idlist}) ORDER BY flight_id, zayavka_id, movement_type, id");
echo "Total: " . count($wms) . " rows\n";
foreach ($wms as $w) {
    echo "  flight={$w['flight_id']} type={$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} fkko={$w['fkko_code']} mass={$w['mass_netto']} brutto={$w['mass_brutto']} vol={$w['volume']} status={$w['status']} comment={$w['comment']}\n";
}

// --- duplicates ---
$dups = dbAll($pdo, "SELECT flight_id, movement_type, warehouse_id, zayavka_id, COUNT(*) AS cnt FROM warehouse_movements WHERE flight_id IN ({$idlist}) GROUP BY 1, 2, 3, 4 HAVING COUNT(*) > 1");
echo "Duplicates: " . count($dups) . "\n";
foreach ($dups as $d) {
    echo "  DUPLICATE: flight={$d['flight_id']} type={$d['movement_type']} wh={$d['warehouse_id']} zid={$d['zayavka_id']} cnt={$d['cnt']}\n";
}

// --- driver ---
echo "\n=== DRIVER CHECK ===\n";
if (!empty($flightIds)) {
    $df = dbAll($pdo, "SELECT id, driver_id, status FROM flights WHERE id = {$flightIds[0]}");
    if ($df) echo "Flight #{$flightIds[0]}: driver_id={$df[0]['driver_id']} status={$df[0]['status']}\n";
}

echo "\n=== TEST IDs: " . implode(',', $flightIds) . " ===\n";
echo "DONE\n";
