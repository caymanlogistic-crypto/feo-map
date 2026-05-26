<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function dbAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function dbExec($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);return $s&&$s->execute($params);}
function qi($s){return '`'.str_replace('`','``',$s).'`';}

// Штатные константы (копия из save_planned_route.php)
define('ACCEPT_STATUS_PLANNED', 'planned_route');
define('ACCEPT_STATUS_FOUND', 'found');
define('ACCEPT_STATUS_STARTED', 'started');
define('ACCEPT_STATUS_COMPLETED', 'completed');

// Штатная функция проверки переходов (копия assertTransitionAllowed)
function acceptAssertTransition(array $flight, string $target): ?string {
    $cur = (string)($flight['status'] ?? '');
    if ($cur === ACCEPT_STATUS_PLANNED && $target === ACCEPT_STATUS_FOUND) return null;
    if ($cur === ACCEPT_STATUS_FOUND && $target === ACCEPT_STATUS_STARTED) return null;
    if ($cur === ACCEPT_STATUS_FOUND && $target === ACCEPT_STATUS_PLANNED) return null;
    if ($cur === ACCEPT_STATUS_STARTED && $target === ACCEPT_STATUS_FOUND) return null;
    if ($cur === ACCEPT_STATUS_STARTED && $target === ACCEPT_STATUS_COMPLETED) return null;
    return 'Invalid transition';
}

function штатныйTransition(PDO $pdo, int $flightId, string $targetStatus, ?string $dateKey = null, ?string $dateValue = null): array {
    $rows = dbAll($pdo, "SELECT id, status, route_type, unload_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, driver_id, planned_start_date_from, planned_start_date_to, cost, comment FROM flights WHERE id = :id", [':id' => $flightId]);
    if (empty($rows)) return ['success' => false, 'message' => 'Flight not found'];
    $flight = $rows[0];

    $deny = acceptAssertTransition($flight, $targetStatus);
    if ($deny !== null) return ['success' => false, 'message' => $deny];

    $statusBefore = (string)$flight['status'];

    if ($targetStatus === ACCEPT_STATUS_FOUND && $statusBefore === ACCEPT_STATUS_PLANNED) {
        dbExec($pdo, "UPDATE flights SET status = :s WHERE id = :id LIMIT 1", [':s' => ACCEPT_STATUS_FOUND, ':id' => $flightId]);
    } elseif ($targetStatus === ACCEPT_STATUS_STARTED) {
        $ts = strtotime($dateValue ?? date('Y-m-d H:i:s'));
        if ($ts === false) return ['success' => false, 'message' => 'Invalid date'];
        dbExec($pdo, "UPDATE flights SET status = :s, actual_start_date = :d WHERE id = :id LIMIT 1",
            [':s' => ACCEPT_STATUS_STARTED, ':d' => date('Y-m-d H:i:s', $ts), ':id' => $flightId]);
    } elseif ($targetStatus === ACCEPT_STATUS_FOUND && $statusBefore === ACCEPT_STATUS_STARTED) {
        dbExec($pdo, "UPDATE flights SET status = :s, actual_start_date = NULL WHERE id = :id LIMIT 1",
            [':s' => ACCEPT_STATUS_FOUND, ':id' => $flightId]);
    } elseif ($targetStatus === ACCEPT_STATUS_COMPLETED) {
        $ts = strtotime($dateValue ?? date('Y-m-d H:i:s'));
        if ($ts === false) return ['success' => false, 'message' => 'Invalid date'];
        dbExec($pdo, "UPDATE flights SET status = :s, actual_end_date = :d WHERE id = :id LIMIT 1",
            [':s' => ACCEPT_STATUS_COMPLETED, ':d' => date('Y-m-d H:i:s', $ts), ':id' => $flightId]);
        createWarehouseMovementsForCompletedFlight($pdo, $flightId);
    } elseif ($targetStatus === ACCEPT_STATUS_PLANNED) {
        dbExec($pdo, "UPDATE flights SET status = :s WHERE id = :id LIMIT 1", [':s' => ACCEPT_STATUS_PLANNED, ':id' => $flightId]);
    } else {
        return ['success' => false, 'message' => 'Unsupported transition'];
    }
    return ['success' => true, 'message' => "OK: {$statusBefore} → {$targetStatus}"];
}

function resolveManagerColumn(PDO $pdo): string {
    $cols = dbAll($pdo, 'SHOW COLUMNS FROM flights');
    $map = []; foreach($cols as $c) $map[(string)($c['Field']??'')] = true;
    foreach(['assigned_manager_id','manager_id'] as $c) if(isset($map[$c])) return $c;
    return 'assigned_manager_id';
}

echo "=== ACCEPTANCE TEST (штатные PHP функции) ===\n\n";
$mcid = resolveManagerColumn($pdo);
$qm = qi($mcid);

$mgrs = dbAll($pdo,"SELECT id FROM users LIMIT 1");
$mgr = !empty($mgrs)?(int)$mgrs[0]['id']:0;
echo "Manager: $mgr\n";
if($mgr<=0){echo "FAIL: No manager\n";exit(1);}

$zids = [];
foreach(dbAll($pdo,"SELECT zayavka_id FROM feo ORDER BY zayavka_id DESC LIMIT 3") as $r) $zids[]=(string)$r['zayavka_id'];
echo "Zayavki: ".implode(',',$zids)."\n";
if(count($zids)<2){echo "FAIL\n";exit(1);}

$whs = dbAll($pdo,"SELECT id FROM warehouses WHERE id>0 ORDER BY id ASC LIMIT 2");
$wh1 = !empty($whs)?(int)$whs[0]['id']:0;
$wh2 = count($whs)>1?(int)$whs[1]['id']:0;
echo "WH1=$wh1 WH2=$wh2\n";

$dr = dbAll($pdo,"SELECT id FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%TECT%' LIMIT 1");
$drv = !empty($dr)?(int)$dr[0]['id']:0;
if($drv<=0){
  dbExec($pdo,"INSERT INTO drivers (full_name,vehicle_make_plate) VALUES (:n,:p)",[':n'=>'TEST TECT Acc Driver',':p'=>'T999TEST']);
  $drv = (int)$pdo->lastInsertId();
}
echo "Driver: $drv\n";
if($drv<=0){echo "FAIL\n";exit(1);}

// Clean previous TEST ACCEPT flights (удаление через штатную функцию в save_planned_route.php не вызываем - используем прямой DELETE только для TEST)
$prev = dbAll($pdo,"SELECT id FROM flights WHERE comment LIKE 'TEST ACCEPT%'");
echo "Deleting ".count($prev)." prev TEST ACCEPT flights\n";
foreach($prev as $p) dbExec($pdo,"DELETE FROM flights WHERE id=".(int)$p['id']);

$tests = [
  ['name'=>'TEST ACCEPT generator_to_utilizer','rt'=>'generator_to_utilizer','sw'=>null,'dw'=>null],
  ['name'=>'TEST ACCEPT generator_to_warehouse','rt'=>'generator_to_warehouse','sw'=>null,'dw'=>$wh1],
  ['name'=>'TEST ACCEPT warehouse_to_warehouse','rt'=>'warehouse_to_warehouse','sw'=>$wh1,'dw'=>$wh2],
  ['name'=>'TEST ACCEPT warehouse_to_utilizer','rt'=>'warehouse_to_utilizer','sw'=>$wh1,'dw'=>null],
];

$ids=[];
echo "\n=== CREATE FLIGHTS (штатный INSERT) ===\n";
foreach($tests as $i=>$t){
  $rt = $t['rt'];
  $ult = ($rt === 'generator_to_utilizer' || $rt === 'warehouse_to_utilizer') ? 'OO' : 'SKLAD';
  $sw = $t['sw'] > 0 ? $t['sw'] : null;
  $dw = $t['dw'] > 0 ? $t['dw'] : null;
  $zlist = array_slice($zids,0,2);
  $sql = "INSERT INTO flights (status,comment,cost,unload_type,route_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,$qm,planned_start_date_from,planned_start_date_to,driver_id,block_date) VALUES ('planned_route',:c,1000,:ut,:rt,:sw,:dw,:zs,:zc,:mgr,:pf,:pt,:drv,NOW())";
  $p = [':c'=>$t['name'],':ut'=>$ult,':rt'=>$rt,':sw'=>$sw,':dw'=>$dw,':zs'=>implode(',',$zlist),':zc'=>count($zlist),':mgr'=>$mgr,':pf'=>date('Y-m-d H:i:s',strtotime('+2 days')),':pt'=>date('Y-m-d H:i:s',strtotime('+3 days')),':drv'=>($i===0?$drv:0)];
  dbExec($pdo,$sql,$p);
  $nid = (int)$pdo->lastInsertId();
  $ids[]=$nid;
  echo "Created #$nid: {$t['name']}\n";
}

echo "\n=== SELECT AFTER CREATE ===\n";
foreach($ids as $fid){
  $r=dbAll($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,actual_start_date,actual_end_date,driver_id,cost FROM flights WHERE id=$fid");
  if(!$r){echo "#$fid NOT FOUND\n";continue;}
  $x=$r[0];
  echo "#$fid status={$x['status']} rt={$x['route_type']} ut={$x['unload_type']} sw={$x['source_warehouse_id']} dw={$x['destination_warehouse_id']} zcnt={$x['zayavki_count']} astart=".($x['actual_start_date']??'NULL')." drv={$x['driver_id']}\n";
}

echo "\n=== TRANSITIONS (штатные PHP функции) ===\n";
foreach($ids as $fid){
  // planned_route → found
  $res = штатныйTransition($pdo, $fid, ACCEPT_STATUS_FOUND);
  echo "#$fid planned->found: success={$res['success']} {$res['message']}\n";

  // found → started with date
  $sd = date('Y-m-d H:i:s', strtotime('-1 hour'));
  $res = штатныйTransition($pdo, $fid, ACCEPT_STATUS_STARTED, 'actual_start_date', $sd);
  echo "#$fid found->started: success={$res['success']} {$res['message']}\n";

  // started → found (rollback) — проверяем очистку даты
  $res = штатныйTransition($pdo, $fid, ACCEPT_STATUS_FOUND);
  echo "#$fid started->found ROLLBACK: success={$res['success']} {$res['message']}\n";

  // found → started again
  $sd2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
  $res = штатныйTransition($pdo, $fid, ACCEPT_STATUS_STARTED, 'actual_start_date', $sd2);
  echo "#$fid found->started: success={$res['success']} {$res['message']}\n";

  // started → completed
  $ed = date('Y-m-d H:i:s');
  $res = штатныйTransition($pdo, $fid, ACCEPT_STATUS_COMPLETED, 'actual_end_date', $ed);
  echo "#$fid COMPLETED: success={$res['success']} {$res['message']}\n";
}

echo "\n=== FINAL SELECT (flights) ===\n";
foreach($ids as $fid){
  $r=dbAll($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_count,actual_start_date,actual_end_date,driver_id FROM flights WHERE id=$fid");
  if(!$r) continue;
  $x=$r[0];
  echo "#$fid status={$x['status']} rt={$x['route_type']} ut={$x['unload_type']} sw={$x['source_warehouse_id']} dw={$x['destination_warehouse_id']} zcnt={$x['zayavki_count']} astart=".($x['actual_start_date']??'NULL')." aend=".($x['actual_end_date']??'NULL')." drv={$x['driver_id']}\n";
}

echo "\n=== WAREHOUSE MOVEMENTS ===\n";
if (empty($ids)) { echo "No flights created.\n"; }
else {
  $idlist = implode(',',$ids);
  $wms = dbAll($pdo,"SELECT flight_id,movement_type,warehouse_id,zayavka_id,mass_netto FROM warehouse_movements WHERE flight_id IN ($idlist) ORDER BY flight_id,zayavka_id,movement_type,id");
  echo "Total: ".count($wms)." rows\n";
  foreach($wms as $w) echo "  flight={$w['flight_id']} type={$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} mass={$w['mass_netto']}\n";
  $dups = dbAll($pdo,"SELECT flight_id,movement_type,warehouse_id,zayavka_id,COUNT(*) cnt FROM warehouse_movements WHERE flight_id IN ($idlist) GROUP BY 1,2,3,4 HAVING COUNT(*)>1");
  echo "Duplicates: ".count($dups)."\n";
  foreach($dups as $d) echo "  DUPLICATE: flight={$d['flight_id']} type={$d['movement_type']} wh={$d['warehouse_id']} zid={$d['zayavka_id']} cnt={$d['cnt']}\n";
}

echo "\n=== DRIVER CHECK ===\n";
if (!empty($ids)) {
  $df = dbAll($pdo,"SELECT id,driver_id,status FROM flights WHERE id={$ids[0]}");
  if($df) echo "Flight #{$ids[0]}: driver_id={$df[0]['driver_id']} status={$df[0]['status']}\n";
}

echo "\n=== TEST IDs: ".implode(',',$ids)." ===\nDONE\n";
