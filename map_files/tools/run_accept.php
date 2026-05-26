<?php
// CLI-only acceptance test using штатный HTTP endpoint for all transitions
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function dbAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function dbExec($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);return $s&&$s->execute($params);}
function qi($s){return '`'.str_replace('`','``',$s).'`';}

function resolveFlightsManagerColumn2(PDO $pdo): ?string {
    $m = []; $s = $pdo->query('SHOW COLUMNS FROM flights');
    $r = $s ? $s->fetchAll(PDO::FETCH_COLUMN) : []; foreach((array)$r as $c) $m[(string)$c]=true;
    foreach(['assigned_manager_id','manager_id'] as $c) if(isset($m[$c])) return $c;
    return null;
}

// Штатный HTTP endpoint
$endpointUrl = 'http://spugovxsim.temp.swtest.ru/fregat/feo/map_files/save_planned_route.php';

function штатныйApiCall(string $url, array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
        'content' => $json,
        'timeout' => 30,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['success' => false, 'message' => 'HTTP request failed'];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'Invalid JSON'];
}

function штатныйTransition(int $flightId, string $targetStatus, string $dateKey, string $dateValue): array {
    global $endpointUrl;
    $payload = ['action' => 'transition', 'id' => $flightId, 'target_status' => $targetStatus];
    if ($dateValue !== '') $payload[$dateKey] = $dateValue;
    return штатныйApiCall($endpointUrl, $payload);
}

echo "=== ACCEPTANCE TEST (штатный HTTP endpoint) ===\n\n";
$mcid = resolveFlightsManagerColumn2($pdo) ?? 'assigned_manager_id';
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

// Clean only TEST ACCEPT flights via штатный endpoint (delete action)
$prev = dbAll($pdo,"SELECT id FROM flights WHERE comment LIKE 'TEST ACCEPT%'");
echo "Deleting ".count($prev)." prev TEST ACCEPT flights via штатный endpoint\n";
foreach($prev as $p) {
  $res = штатныйApiCall($endpointUrl, ['action' => 'delete_route', 'id' => (int)$p['id']]);
}

$tests = [
  ['name'=>'TEST ACCEPT generator_to_utilizer','rt'=>'generator_to_utilizer','sw'=>null,'dw'=>null],
  ['name'=>'TEST ACCEPT generator_to_warehouse','rt'=>'generator_to_warehouse','sw'=>null,'dw'=>$wh1],
  ['name'=>'TEST ACCEPT warehouse_to_warehouse','rt'=>'warehouse_to_warehouse','sw'=>$wh1,'dw'=>$wh2],
  ['name'=>'TEST ACCEPT warehouse_to_utilizer','rt'=>'warehouse_to_utilizer','sw'=>$wh1,'dw'=>null],
];

$ids=[];
echo "\n=== CREATE FLIGHTS (штатный HTTP endpoint) ===\n";
foreach($tests as $i=>$t){
  $zlist = array_slice($zids,0,2);
  $payload = [
    'action' => 'save',
    'name' => $t['name'],
    'comment' => $t['name'],
    'route_type' => $t['rt'],
    'source_warehouse_id' => $t['sw'],
    'destination_warehouse_id' => $t['dw'],
    'zayavki_ids' => implode(',',$zlist),
    'driver_id' => ($i===0 ? $drv : 0),
    'assigned_manager_id' => $mgr,
    'planned_start_date_from' => date('Y-m-d H:i:s', strtotime('+2 days')),
    'planned_start_date_to' => date('Y-m-d H:i:s', strtotime('+3 days')),
    'cost' => '1000',
  ];
  $res = штатныйApiCall($endpointUrl, $payload);
  $nid = $res['id'] ?? 0;
  if ($nid > 0) { $ids[] = (int)$nid; echo "Created #$nid: {$t['name']}\n"; }
  else { echo "FAIL create {$t['name']}: {$res['message']}\n"; }
}
if(count($ids)<4){echo "WARN: Only ".count($ids)."/4 flights created.\n";}

echo "\n=== SELECT AFTER CREATE ===\n";
foreach($ids as $fid){
  $r=dbAll($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,actual_start_date,actual_end_date,driver_id,cost FROM flights WHERE id=$fid");
  if(!$r){echo "#$fid NOT FOUND\n";continue;}
  $x=$r[0];
  echo "#$fid status={$x['status']} rt={$x['route_type']} ut={$x['unload_type']} sw={$x['source_warehouse_id']} dw={$x['destination_warehouse_id']} zcnt={$x['zayavki_count']} astart=".($x['actual_start_date']??'NULL')." drv={$x['driver_id']}\n";
}

echo "\n=== TRANSITIONS (штатный HTTP endpoint) ===\n";
foreach($ids as $fid){
  // planned_route → found
  $res = штатныйTransition($fid, 'found', '', '');
  echo "#$fid planned->found: success={$res['success']} {$res['message']}\n";

  // found → started with date
  $sd = date('Y-m-d H:i:s', strtotime('-1 hour'));
  $res = штатныйTransition($fid, 'started', 'actual_start_date', $sd);
  echo "#$fid found->started: success={$res['success']} {$res['message']}\n";

  // started → found (rollback) — проверяем очистку даты
  $res = штатныйTransition($fid, 'found', '', '');
  echo "#$fid started->found ROLLBACK: success={$res['success']} {$res['message']}\n";

  // found → started again
  $sd2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
  $res = штатныйTransition($fid, 'started', 'actual_start_date', $sd2);
  echo "#$fid found->started: success={$res['success']} {$res['message']}\n";

  // started → completed
  $ed = date('Y-m-d H:i:s');
  $res = штатныйTransition($fid, 'completed', 'actual_end_date', $ed);
  echo "#$fid COMPLETED: success={$res['success']} {$res['message']}\n";
  if (isset($res['warehouse_movements'])) {
    echo "#$fid WM: created={$res['warehouse_movements']['created']} skipped={$res['warehouse_movements']['skipped']}\n";
  }
}

echo "\n=== FINAL SELECT (flights) ===\n";
foreach($ids as $fid){
  $r=dbAll($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_count,actual_start_date,actual_end_date,driver_id FROM flights WHERE id=$fid");
  if(!$r) continue;
  $x=$r[0];
  echo "#$fid status={$x['status']} rt={$x['route_type']} ut={$x['unload_type']} sw={$x['source_warehouse_id']} dw={$x['destination_warehouse_id']} zcnt={$x['zayavki_count']} astart=".($x['actual_start_date']??'NULL')." aend=".($x['actual_end_date']??'NULL')." drv={$x['driver_id']}\n";
}

echo "\n=== WAREHOUSE MOVEMENTS ===\n";
$idlist = implode(',',$ids);
$wms = dbAll($pdo,"SELECT flight_id,movement_type,warehouse_id,zayavka_id,mass_netto FROM warehouse_movements WHERE flight_id IN ($idlist) ORDER BY flight_id,zayavka_id,movement_type,id");
echo "Total: ".count($wms)." rows\n";
foreach($wms as $w) echo "  flight={$w['flight_id']} type={$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} mass={$w['mass_netto']}\n";

$dups = dbAll($pdo,"SELECT flight_id,movement_type,warehouse_id,zayavka_id,COUNT(*) cnt FROM warehouse_movements WHERE flight_id IN ($idlist) GROUP BY 1,2,3,4 HAVING COUNT(*)>1");
echo "Duplicates: ".count($dups)."\n";
foreach($dups as $d) echo "  DUPLICATE: flight={$d['flight_id']} type={$d['movement_type']} wh={$d['warehouse_id']} zid={$d['zayavka_id']} cnt={$d['cnt']}\n";

echo "\n=== DRIVER CHECK ===\n";
$df = dbAll($pdo,"SELECT id,driver_id,status FROM flights WHERE id={$ids[0]}");
if($df) echo "Flight #{$ids[0]}: driver_id={$df[0]['driver_id']} status={$df[0]['status']}\n";

echo "\n=== TEST IDs: ".implode(',',$ids)." ===\nDONE\n";
