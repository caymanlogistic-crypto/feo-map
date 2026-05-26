<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/warehouse_movements.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "FATAL: No DB.\n"); exit(1); }

$flightId = 189;
$flight = $pdo->query("SELECT id,status,route_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,comment FROM flights WHERE id={$flightId}")->fetch(PDO::FETCH_ASSOC);
if (!$flight) { echo "FAIL: Flight #189 not found.\n"; exit(1); }
echo "Flight: id={$flight['id']} status={$flight['status']} route_type={$flight['route_type']} src_wh={$flight['source_warehouse_id']} dst_wh={$flight['destination_warehouse_id']}\n";
$wmBefore=(int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id={$flightId}")->fetchColumn();
echo "WM before: {$wmBefore}\n";
if($wmBefore>0){echo "EXISTING ROWS:\n"; $ex=$pdo->query("SELECT id,movement_type,warehouse_id,zayavka_id,status FROM warehouse_movements WHERE flight_id={$flightId}")->fetchAll(PDO::FETCH_ASSOC); foreach($ex as $r){echo "  {$r['movement_type']} wh={$r['warehouse_id']} z={$r['zayavka_id']} status={$r['status']}\n";} exit(0);}
$result=createWarehouseMovementsForCompletedFlight($pdo,$flightId);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
$wmAfter=(int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id={$flightId}")->fetchColumn();
echo "WM after: {$wmAfter}\n";
if($wmAfter>0){$rows=$pdo->query("SELECT id,movement_type,warehouse_id,zayavka_id,fkko_code,ROUND(mass_netto,3)m_netto,status FROM warehouse_movements WHERE flight_id={$flightId} ORDER BY zayavka_id,movement_type")->fetchAll(PDO::FETCH_ASSOC);foreach($rows as $r){foreach($r as $k=>$v)echo "  {$k}={$v}\n";echo "\n";}}
echo "DONE\n";
