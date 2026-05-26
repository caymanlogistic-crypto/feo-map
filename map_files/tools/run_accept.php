<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/save_planned_route.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function q($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function qe($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);return $s&&$s->execute($params);}

echo "=== ACCEPTANCE TEST DIRECT ===\n\n";
$mcid = resolveFlightsManagerColumn($pdo) ?? 'assigned_manager_id';
$qm = '`'.str_replace('`','``',$mcid).'`';

// Find manager
$mgrs = q($pdo,"SELECT id FROM users LIMIT 1");
$mgr = !empty($mgrs)?(int)$mgrs[0]['id']:0;
echo "Manager: $mgr\n";
if($mgr<=0){echo "FAIL: No manager\n";exit(1);}

// Find zayavki
$zids = [];
$zr = q($pdo,"SELECT zayavka_id FROM feo ORDER BY zayavka_id DESC LIMIT 3");
foreach($zr as $r){$zids[]=(string)$r['zayavka_id'];}
echo "Zayavki: ".implode(',',$zids)."\n";
if(count($zids)<2){echo "FAIL: Need >=2 zayavki\n";exit(1);}

// Find warehouses
$whs = q($pdo,"SELECT id FROM warehouses WHERE id>0 ORDER BY id ASC LIMIT 2");
$wh1 = !empty($whs)?(int)$whs[0]['id']:0;
$wh2 = count($whs)>1?(int)$whs[1]['id']:0;
echo "WH1=$wh1 WH2=$wh2\n";

// Find or create test driver
$dr = q($pdo,"SELECT id FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%ТЕСТ%' LIMIT 1");
$drv = !empty($dr)?(int)$dr[0]['id']:0;
if($drv<=0){
  qe($pdo,"INSERT INTO drivers (full_name,vehicle_make_plate) VALUES (:n,:p)",[':n'=>'TEST ТЕСТ Acc Driver',':p'=>'T999TEST']);
  $drv = (int)$pdo->lastInsertId();
}
echo "Driver: $drv\n";
if($drv<=0){echo "FAIL: No driver\n";exit(1);}

// Delete previous TEST ACCEPT flights
$prev = q($pdo,"SELECT id FROM flights WHERE comment LIKE 'TEST ACCEPT%'");
echo "Deleting ".count($prev)." previous TEST ACCEPT flights\n";
foreach($prev as $p){ qe($pdo,"DELETE FROM flights WHERE id=".(int)$p['id']); }

$tests = [
  ['name'=>'TEST ACCEPT generator_to_utilizer','rt'=>'generator_to_utilizer','sw'=>null,'dw'=>null,'ut'=>'OO'],
  ['name'=>'TEST ACCEPT generator_to_warehouse','rt'=>'generator_to_warehouse','sw'=>null,'dw'=>$wh1,'ut'=>'SKLAD'],
  ['name'=>'TEST ACCEPT warehouse_to_warehouse','rt'=>'warehouse_to_warehouse','sw'=>$wh1,'dw'=>$wh2,'ut'=>'SKLAD'],
  ['name'=>'TEST ACCEPT warehouse_to_utilizer','rt'=>'warehouse_to_utilizer','sw'=>$wh1,'dw'=>null,'ut'=>'OO'],
];

$ids=[];
echo "\n=== CREATE FLIGHTS ===\n";
foreach($tests as $i=>$t){
  $rt=normalizeRouteType($t['rt'],$t['ut']);
  $ult=resolveUnloadTypeByRouteType($rt);
  $sw=normalizeWarehouseId($pdo,$t['sw']);
  $dw=normalizeWarehouseId($pdo,$t['dw']);
  $zlist = array_slice($zids,0,2);
  $sql = "INSERT INTO flights (status,comment,cost,unload_type,route_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,$qm,planned_start_date_from,planned_start_date_to,driver_id,block_date) VALUES ('planned_route',:c,1000,:ut,:rt,:sw,:dw,:zs,:zc,:mgr,:pf,:pt,:drv,NOW())";
  $params = [':c'=>$t['name'],':ut'=>$ult,':rt'=>$rt,':sw'=>$sw,':dw'=>$dw,':zs'=>implode(',',$zlist),':zc'=>count($zlist),':mgr'=>$mgr,':pf'=>date('Y-m-d H:i:s',strtotime('+2 days')),':pt'=>date('Y-m-d H:i:s',strtotime('+3 days')),':drv'=>($i===0?$drv:0)];
  qe($pdo,$sql,$params);
  $nid = (int)$pdo->lastInsertId();
  $ids[]=$nid;
  echo "Created #$nid: {$t['name']}\n";
}

echo "\n=== SELECT AFTER CREATE ===\n";
foreach($ids as $fid){
  $rows=q($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,actual_start_date,actual_end_date,driver_id,cost,comment FROM flights WHERE id=$fid");
  if(empty($rows)){echo "#$fid NOT FOUND\n";continue;}
  $r=$rows[0];
  echo "#$fid status={$r['status']} rt={$r['route_type']} ut={$r['unload_type']} sw={$r['source_warehouse_id']} dw={$r['destination_warehouse_id']} zids={$r['zayavki_ids']} zcnt={$r['zayavki_count']} astart=".($r['actual_start_date']??'NULL')." aend=".($r['actual_end_date']??'NULL')." drv={$r['driver_id']} cost={$r['cost']}\n";
}

echo "\n=== TRANSITIONS ===\n";
foreach($ids as $fid){
  $fl = q($pdo,"SELECT * FROM flights WHERE id=$fid");
  if(empty($fl)) continue;
  $fl=$fl[0];

  qe($pdo,"UPDATE flights SET status='found' WHERE id=$fid");
  $fl2 = q($pdo,"SELECT status,actual_start_date FROM flights WHERE id=$fid")[0];
  echo "#$fid planned->found: status={$fl2['status']}\n";

  $sd = date('Y-m-d H:i:s',strtotime('-1 hour'));
  qe($pdo,"UPDATE flights SET status='started',actual_start_date='$sd' WHERE id=$fid");
  $fl3 = q($pdo,"SELECT status,actual_start_date FROM flights WHERE id=$fid")[0];
  echo "#$fid found->started: status={$fl3['status']} actual_start={$fl3['actual_start_date']}\n";

  qe($pdo,"UPDATE flights SET status='found',actual_start_date=NULL WHERE id=$fid");
  $fl4 = q($pdo,"SELECT status,actual_start_date FROM flights WHERE id=$fid")[0];
  echo "#$fid started->found ROLLBACK: status={$fl4['status']} actual_start=".($fl4['actual_start_date']??'NULL')."\n";

  $sd2 = date('Y-m-d H:i:s',strtotime('-2 hours'));
  qe($pdo,"UPDATE flights SET status='started',actual_start_date='$sd2' WHERE id=$fid");
  $fl5 = q($pdo,"SELECT status,actual_start_date FROM flights WHERE id=$fid")[0];
  echo "#$fid found->started: status={$fl5['status']} actual_start={$fl5['actual_start_date']}\n";

  $ed = date('Y-m-d H:i:s');
  qe($pdo,"UPDATE flights SET status='completed',actual_end_date='$ed' WHERE id=$fid");
  $fl6 = q($pdo,"SELECT status,actual_start_date,actual_end_date FROM flights WHERE id=$fid")[0];
  echo "#$fid COMPLETED: status={$fl6['status']} actual_end={$fl6['actual_end_date']}\n";

  $wm = createWarehouseMovementsForCompletedFlight($pdo,$fid);
  echo "#$fid WM: created={$wm['created']} skipped={$wm['skipped']} errors=".count($wm['errors']??[])."\n";
}

echo "\n=== WAREHOUSE MOVEMENTS ===\n";
$idlist = implode(',',$ids);
$wms = q($pdo,"SELECT id,flight_id,movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,zayavka_id,mass_netto,status FROM warehouse_movements WHERE flight_id IN ($idlist) ORDER BY flight_id,zayavka_id,movement_type,id");
echo "Total: ".count($wms)." rows\n";
foreach($wms as $w){
  echo "  flight={$w['flight_id']} type={$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} mass={$w['mass_netto']}\n";
}

$dups = q($pdo,"SELECT flight_id,movement_type,warehouse_id,zayavka_id,COUNT(*) AS cnt FROM warehouse_movements WHERE flight_id IN ($idlist) GROUP BY 1,2,3,4 HAVING COUNT(*)>1");
echo "Duplicates: ".count($dups)."\n";
foreach($dups as $d){echo "  DUPLICATE: flight={$d['flight_id']} type={$d['movement_type']} wh={$d['warehouse_id']} zid={$d['zayavka_id']} cnt={$d['cnt']}\n";}

echo "\n=== FINAL FLIGHT STATE ===\n";
foreach($ids as $fid){
  $rows=q($pdo,"SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,zayavki_count,actual_start_date,actual_end_date,driver_id,cost FROM flights WHERE id=$fid");
  if(empty($rows)){echo "#$fid NOT FOUND\n";continue;}
  $r=$rows[0];
  echo "#$fid status={$r['status']} rt={$r['route_type']} ut={$r['unload_type']} sw={$r['source_warehouse_id']} dw={$r['destination_warehouse_id']} zcnt={$r['zayavki_count']} astart=".($r['actual_start_date']??'NULL')." aend=".($r['actual_end_date']??'NULL')." drv={$r['driver_id']}\n";
}

echo "\n=== DRIVER CHECK ===\n";
$df = q($pdo,"SELECT id,driver_id,status FROM flights WHERE id={$ids[0]}");
if(!empty($df)){echo "Flight #{$ids[0]}: driver_id={$df[0]['driver_id']} status={$df[0]['status']}\n";}

echo "\n=== TEST IDs: ".implode(',',$ids)." ===\n";
echo "DONE\n";
