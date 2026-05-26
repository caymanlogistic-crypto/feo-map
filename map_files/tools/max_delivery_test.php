<?php
// CLI-only MAX delivery test — creates TEST MAX CHECK flight and runs all transitions
// Checks real MAX sending via штатные PHP functions
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function dbAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function dbExec($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);return $s&&$s->execute($params);}
function qi($s){return '`'.str_replace('`','``',$s).'`';}
function tblExists($pdo,$tbl){static $c=[];if(isset($c[$tbl]))return $c[$tbl];$s=$pdo->query("SHOW TABLES LIKE '".str_replace("'","''",$tbl)."'");$c[$tbl]=(bool)$s->fetchColumn();return $c[$tbl];}

// Штатные константы
define('S_PLANNED', 'planned_route');
define('S_FOUND', 'found');
define('S_STARTED', 'started');
define('S_COMPLETED', 'completed');

// Копия assertTransitionAllowed
function assertTransitionAllowed(array $flight, string $target): ?string {
    $cur = (string)($flight['status'] ?? '');
    if ($cur===S_PLANNED && $target===S_FOUND) return null;
    if ($cur===S_FOUND && $target===S_STARTED) return null;
    if ($cur===S_FOUND && $target===S_PLANNED) return null;
    if ($cur===S_STARTED && $target===S_FOUND) return null;
    if ($cur===S_STARTED && $target===S_COMPLETED) return null;
    return 'Invalid transition';
}

function resolveManagerColumn(PDO $pdo): string {
    $cols = dbAll($pdo,'SHOW COLUMNS FROM flights');
    $m=[];foreach($cols as $c)$m[(string)($c['Field']??'')]=true;
    foreach(['assigned_manager_id','manager_id'] as $c) if(isset($m[$c])) return $c;
    return 'assigned_manager_id';
}

echo "=== MAX DELIVERY TEST ===\n\n";

// Resources
$mcid = resolveManagerColumn($pdo); $qm = qi($mcid);
$mgrs = dbAll($pdo,"SELECT id FROM users LIMIT 1"); $mgr = !empty($mgrs)?(int)$mgrs[0]['id']:0;
$zids = []; foreach(dbAll($pdo,"SELECT zayavka_id FROM feo ORDER BY zayavka_id DESC LIMIT 3") as $r) $zids[]=(string)$r['zayavka_id'];
$whs = dbAll($pdo,"SELECT id FROM warehouses WHERE id>0 ORDER BY id ASC LIMIT 2");
$wh1 = !empty($whs)?(int)$whs[0]['id']:0; $wh2 = count($whs)>1?(int)$whs[1]['id']:0;
$dr = dbAll($pdo,"SELECT id FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%TECT%' LIMIT 1");
$drv = !empty($dr)?(int)$dr[0]['id']:0;

echo "Manager: $mgr, Driver: $drv, WH1=$wh1, WH2=$wh2\n";

// Clean previous TEST MAX CHECK
$prev = dbAll($pdo,"SELECT id FROM flights WHERE comment='TEST MAX CHECK'");
foreach($prev as $p) dbExec($pdo,"DELETE FROM flights WHERE id=".(int)$p['id']);

// Create test flight
$zlist = array_slice($zids,0,2);
$sql = "INSERT INTO flights (status,comment,cost,unload_type,route_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,$qm,planned_start_date_from,planned_start_date_to,driver_id,block_date) VALUES ('planned_route','TEST MAX CHECK',1000,'SKLAD','generator_to_warehouse',NULL,:dw,:zs,:zc,:mgr,:pf,:pt,:drv,NOW())";
dbExec($pdo,$sql,[':dw'=>$wh1,':zs'=>implode(',',$zlist),':zc'=>count($zlist),':mgr'=>$mgr,':pf'=>date('Y-m-d H:i:s',strtotime('+2 days')),':pt'=>date('Y-m-d H:i:s',strtotime('+3 days')),':drv'=>($drv>0?$drv:0)]);
$fid = (int)$pdo->lastInsertId();
echo "Created TEST MAX CHECK flight #{$fid}\n\n";

// Transitions with MAX events
$events = [];
$flight = dbAll($pdo,"SELECT * FROM flights WHERE id=$fid")[0];

// 1. planned -> found
dbExec($pdo,"UPDATE flights SET status='found' WHERE id=$fid");
$events[] = ['action'=>'planned->found','event_key'=>'route_planned_to_found','status'=>'found'];

// 2. found -> started
$sd = date('Y-m-d H:i:s', strtotime('-1 hour'));
dbExec($pdo,"UPDATE flights SET status='started',actual_start_date='$sd' WHERE id=$fid");
$events[] = ['action'=>'found->started','event_key'=>'route_found_to_started','status'=>'started'];

// 3. started -> found (rollback)
$oldStart = $sd;
dbExec($pdo,"UPDATE flights SET status='found',actual_start_date=NULL WHERE id=$fid");
$events[] = ['action'=>'started->found ROLLBACK','event_key'=>'route_started_to_found_rollback','status'=>'found','old_actual_start'=>$oldStart];

// 4. found -> planned (rollback)
dbExec($pdo,"UPDATE flights SET status='planned_route' WHERE id=$fid");
$events[] = ['action'=>'found->planned ROLLBACK','event_key'=>'route_found_to_planned_rollback','status'=>'planned_route'];

// 5. planned -> found again
dbExec($pdo,"UPDATE flights SET status='found' WHERE id=$fid");
$events[] = ['action'=>'planned->found (2)','event_key'=>'route_planned_to_found','status'=>'found'];

// 6. found -> started again
$sd2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
dbExec($pdo,"UPDATE flights SET status='started',actual_start_date='$sd2' WHERE id=$fid");

// 7. started -> completed
$ed = date('Y-m-d H:i:s');
dbExec($pdo,"UPDATE flights SET status='completed',actual_end_date='$ed' WHERE id=$fid");
createWarehouseMovementsForCompletedFlight($pdo,$fid);
$events[] = ['action'=>'started->completed','event_key'=>'route_completed','status'=>'completed'];

echo "=== FLIGHT STATE ===\n";
$r = dbAll($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_count,actual_start_date,actual_end_date,driver_id,cost FROM flights WHERE id=$fid")[0];
echo "#{$r['id']} status={$r['status']} rt={$r['route_type']} ut={$r['unload_type']} sw={$r['source_warehouse_id']} dw={$r['destination_warehouse_id']} astart=".($r['actual_start_date']??'NULL')." aend=".($r['actual_end_date']??'NULL')."\n\n";

// NOW — send actual MAX notifications for key events and check logs
echo "=== MAX NOTIFICATION TESTS ===\n";

$testContext = [
    'route_id' => (string)$fid,
    'route_title' => 'TEST MAX CHECK',
    'planned_range' => date('d.m',strtotime('+2 days')).'-'.date('d.m',strtotime('+3 days')),
    'actual_start_short' => date('d.m', strtotime('-2 hours')),
    'driver' => 'TEST Driver',
    'manager' => 'Manager',
    'requests_count' => '2',
    'weight' => '595 kg',
    'route_type_line' => 'Выгрузка на склад: Склад #5',
    'meta_line' => '2 заяв. • 595 кг',
    'message' => 'TEST MAX CHECK message',
    'warehouse_line' => 'Склады: не выбраны → Склад #5',
    'cost' => '1 000 ₽',
    'status_from' => 'found',
    'status_to' => 'completed',
    'old_actual_start_date' => $oldStart,
];

$testEventKeys = [
    'route_planned_to_found',
    'route_found_to_started',
    'route_started_to_found_rollback',
    'route_found_to_planned_rollback',
    'route_completed',
    'route_deleted',
    'route_found_updated',
    'route_started_updated',
];

foreach ($testEventKeys as $ek) {
    $msg = "MAX test: {$ek} for flight #{$fid}";
    $result = sendMaxNotify($msg, 'markdown', ['event_key' => $ek, 'context' => $testContext]);
    $status = $result['success'] ? 'OK' : 'FAIL';
    $skip = $result['skipped'] ?? false;
    $queued = $result['queued'] ?? false;
    $err = $result['error'] ?? '';
    $info = $skip ? ($queued ? 'QUEUED' : 'SKIPPED') : 'SENT';
    echo "  [{$status}] {$ek}: {$info}" . ($err ? " err={$err}" : '') . "\n";
}

// Check logs
echo "\n=== LOG CHECK ===\n";
if (tblExists($pdo,'max_send_log')) {
    $logs = dbAll($pdo, "SELECT event_key, success, created_at FROM max_send_log ORDER BY id DESC LIMIT 10");
    echo "max_send_log: " . count($logs) . " entries\n";
    foreach ($logs as $l) echo "  [" . ((int)($l['success']??0)===1?'OK':'ERR') . "] {$l['event_key']} at {$l['created_at']}\n";
}
if (tblExists($pdo,'max_logs')) {
    $logs = dbAll($pdo, "SELECT event_key, success, status, created_at FROM max_logs ORDER BY id DESC LIMIT 10");
    echo "max_logs: " . count($logs) . " entries\n";
    foreach ($logs as $l) echo "  [" . ((int)($l['success']??0)===1?'OK':'ERR') . "] [{$l['status']}] {$l['event_key']} at {$l['created_at']}\n";
}

// WM check
echo "\n=== WM CHECK ===\n";
$wms = dbAll($pdo,"SELECT movement_type,warehouse_id,zayavka_id,mass_netto FROM warehouse_movements WHERE flight_id=$fid ORDER BY zayavka_id,movement_type,id");
echo "Total: " . count($wms) . " rows\n";
foreach($wms as $w) echo "  {$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} mass={$w['mass_netto']}\n";

// API config
echo "\n=== API CONFIG ===\n";
$cfg = mapGetDirectMaxConfig();
echo "  base_url: " . ($cfg['base_url']?:'EMPTY') . "\n";
echo "  token: " . ($cfg['token'] ? substr($cfg['token'],0,8).'...' : 'EMPTY') . "\n";
echo "  chat_id: " . ($cfg['chat_id']?:'EMPTY') . "\n";

echo "\n=== DONE: Flight #{$fid} ===\n";
