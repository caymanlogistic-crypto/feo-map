<?php
/**
 * ONE-TIME BACKFILL for TEST route #188 only (warehouse_to_utilizer).
 * Usage: /usr/bin/php8.4 map_files/tools/backfill_test_188.php
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/warehouse_movements.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "FATAL: No DB.\n"); exit(1); }

$flightId = 188;
$flight = $pdo->query("SELECT id,status,route_type,source_warehouse_id,zayavki_ids,comment FROM flights WHERE id={$flightId}")->fetch(PDO::FETCH_ASSOC);
if (!$flight) { echo "FAIL: Flight #188 not found.\n"; exit(1); }
if (stripos((string)($flight['comment']??''),'TEST')===false&&stripos((string)($flight['comment']??''),'ТЕСТ')===false) { echo "FAIL: Not a test route.\n"; exit(1); }
if (strtolower(trim((string)($flight['route_type']??'')))!=='warehouse_to_utilizer') { echo "FAIL: route_type is not warehouse_to_utilizer.\n"; exit(1); }
if ((int)($flight['source_warehouse_id']??0)<=0) { echo "FAIL: source_warehouse_id empty.\n"; exit(1); }

echo "Flight: id={$flight['id']} status={$flight['status']} route_type={$flight['route_type']}\n";
$wmBefore=(int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id={$flightId}")->fetchColumn();
echo "WM before: {$wmBefore}\n";
if($wmBefore>0){echo "SKIP: Already has movements.\n";exit(0);}

$result=createWarehouseMovementsForCompletedFlight($pdo,$flightId);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";

$wmAfter=(int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id={$flightId}")->fetchColumn();
echo "WM after: {$wmAfter}\n";
if($wmAfter>0){
  $rows=$pdo->query("SELECT id,movement_type,warehouse_id,destination_warehouse_id,zayavka_id,fkko_code,ROUND(mass_netto,3)mass_netto,status FROM warehouse_movements WHERE flight_id={$flightId}")->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){foreach($r as $k=>$v)echo "  {$k}={$v}\n";echo "\n";}
}
echo "DONE\n";
