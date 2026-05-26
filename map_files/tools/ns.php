<?php
if(php_sapi_name()!=='cli'){http_response_code(403);echo"CLI only\n";exit(1);}
require_once dirname(__DIR__).'/bootstrap.php';
echo "=== NEGATIVE STOCK ===\n";
$rows=$pdo->query("SELECT wm.warehouse_id,wm.flight_id,wm.movement_type,wm.fkko_code,wm.zayavka_id,SUM(CASE WHEN wm.movement_type IN('receipt','transfer_in') THEN COALESCE(wm.mass_netto,0) ELSE -COALESCE(wm.mass_netto,0) END) AS stock, f.comment FROM warehouse_movements wm LEFT JOIN flights f ON f.id=wm.flight_id WHERE wm.status='active' GROUP BY wm.warehouse_id,wm.flight_id,wm.movement_type,wm.fkko_code,wm.zayavka_id HAVING stock<0 ORDER BY wm.warehouse_id,wm.flight_id")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){echo "WH#{$r['warehouse_id']} FL#{$r['flight_id']} {$r['movement_type']} Z{$r['zayavka_id']} fkko=".($r['fkko_code']?:'NULL')." stock={$r['stock']} comment=".($r['comment']?:'NULL')."\n";}
echo "=== DONE ===\n";
