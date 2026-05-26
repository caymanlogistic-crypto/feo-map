<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function dbAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function dbExec($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);return $s&&$s->execute($params);}
function qi($s){return '`'.str_replace('`','``',$s).'`';}
function tblExists($pdo,$tbl){static $c=[];if(isset($c[$tbl]))return $c[$tbl];$s=$pdo->query("SHOW TABLES LIKE '".str_replace("'","''",$tbl)."'");$c[$tbl]=(bool)$s->fetchColumn();return $c[$tbl];}

echo "=== ROUTE MAX TEST ===\n\n";

// Resources
function resolveMC($pdo){$c=dbAll($pdo,'SHOW COLUMNS FROM flights');$m=[];foreach($c as $r)$m[(string)($r['Field']??'')]=true;foreach(['assigned_manager_id','manager_id'] as $k)if(isset($m[$k]))return $k;return 'assigned_manager_id';}
$mcid=resolveMC($pdo);$qm=qi($mcid);
$mgr=(int)dbAll($pdo,"SELECT id FROM users LIMIT 1")[0]['id'];
$zids=[];foreach(dbAll($pdo,"SELECT zayavka_id FROM feo ORDER BY zayavka_id DESC LIMIT 2") as $r)$zids[]=(string)$r['zayavka_id'];
$wh=(int)dbAll($pdo,"SELECT id FROM warehouses WHERE id>0 ORDER BY id ASC LIMIT 1")[0]['id'];
$dr=dbAll($pdo,"SELECT id FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%TECT%' LIMIT 1");
$drv=!empty($dr)?(int)$dr[0]['id']:0;

// Clean previous
foreach(dbAll($pdo,"SELECT id FROM flights WHERE comment='TEST MAX ROUTE'") as $p) dbExec($pdo,"DELETE FROM flights WHERE id=".(int)$p['id']);

// Create
$zlist=array_slice($zids,0,2);
dbExec($pdo,"INSERT INTO flights (status,comment,cost,unload_type,route_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,$qm,planned_start_date_from,planned_start_date_to,driver_id,block_date) VALUES ('planned_route','TEST MAX ROUTE',1000,'SKLAD','generator_to_warehouse',NULL,:dw,:zs,:zc,:mgr,:pf,:pt,:drv,NOW())",[':dw'=>$wh,':zs'=>implode(',',$zlist),':zc'=>count($zlist),':mgr'=>$mgr,':pf'=>date('Y-m-d H:i:s',strtotime('+2 days')),':pt'=>date('Y-m-d H:i:s',strtotime('+3 days')),':drv'=>$drv]);
$fid=(int)$pdo->lastInsertId();
echo "Created TEST MAX ROUTE #{$fid}\n\n";

// Transition planned_route -> found
dbExec($pdo,"UPDATE flights SET status='found' WHERE id=$fid");
$flight=dbAll($pdo,"SELECT * FROM flights WHERE id=$fid")[0];
echo "Status: {$flight['status']}\n\n";

// Build context (same as buildRouteEventContext in save_planned_route.php)
$context = [
    'route_id' => (string)$fid,
    'route_title' => 'TEST MAX ROUTE',
    'planned_range' => date('d.m',strtotime($flight['planned_start_date_from']??'')).'-'.date('d.m',strtotime($flight['planned_start_date_to']??'')),
    'actual_range' => 'не указано',
    'driver' => $drv>0?'TEST Driver':'не указан',
    'manager' => 'Manager #'.$mgr,
    'responsible' => 'Manager #'.$mgr,
    'route_type' => 'generator_to_warehouse',
    'route_type_line' => 'Тип рейса: Выгрузка на склад',
    'unload_target_line' => 'Выгрузка на склад',
    'meta_line' => count($zlist).' заяв. • '.(isset($flight['total_mass_tonn'])?round((float)$flight['total_mass_tonn']*1000).' кг':'595 кг'),
    'requests_count' => (string)count($zlist),
    'weight' => '595 кг',
    'cost' => '1 000 ₽',
    'source_warehouse' => '',
    'destination_warehouse' => 'Склад #'.$wh,
    'warehouse_line' => 'Склады: не выбран → Склад #'.$wh,
    'status_from' => 'planned_route',
    'status_to' => 'found',
];

echo "Context:\n";
foreach(['route_id','route_title','route_type','route_type_line','unload_target_line','driver','manager','requests_count','weight','cost','destination_warehouse','warehouse_line','status_from','status_to','planned_range'] as $k) {
    echo "  {$k}: {$context[$k]}\n";
}

echo "\n--- Send MAX: route_planned_to_found ---\n";
$result = sendMaxNotify('ROUTE MAX TEST', 'markdown', ['event_key' => 'route_planned_to_found', 'context' => $context]);
echo "success: " . ($result['success']?'true':'false') . "\n";
echo "skipped: " . ($result['skipped']??'false') . "\n";
echo "queued: " . ($result['queued']??'false') . "\n";
echo "error: " . ($result['error']??'none') . "\n";

echo "\n--- Latest max_send_log (3) ---\n";
if(tblExists($pdo,'max_send_log')){
    foreach(dbAll($pdo,"SELECT event_key, success, message_text, created_at FROM max_send_log ORDER BY id DESC LIMIT 3") as $l){
        $ok=(int)($l['success']??0)===1?'OK':'ERR';
        $msg=mb_substr((string)($l['message_text']??''),0,120);
        echo "[{$ok}] {$l['event_key']} at {$l['created_at']}: {$msg}\n";
    }
}

echo "\n--- Latest max_logs (3) ---\n";
if(tblExists($pdo,'max_logs')){
    foreach(dbAll($pdo,"SELECT event_key, status, created_at FROM max_logs ORDER BY id DESC LIMIT 3") as $l){
        echo "[{$l['status']}] {$l['event_key']} at {$l['created_at']}\n";
    }
}

echo "\n=== DONE: Flight #{$fid} ===\n";
