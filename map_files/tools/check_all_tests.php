<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once __DIR__ . '/../bootstrap.php';
echo "=== WM #190 ===\n";
$c = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id=190")->fetchColumn();
echo "Count: {$c}\n";
echo ($c === 0) ? "PASS: 0 rows for generator_to_utilizer.\n" : "FAIL: {$c} rows!\n";
echo "=== ALL TESTS ===\n";
$rows = $pdo->query("SELECT flight_id, movement_type, warehouse_id, zayavka_id FROM warehouse_movements WHERE flight_id IN (187,188,189,190) ORDER BY flight_id, movement_type, zayavka_id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  #{$r['flight_id']} {$r['movement_type']} wh={$r['warehouse_id']} z={$r['zayavka_id']}\n";
echo "\n=== DUPLICATES ===\n";
$d = $pdo->query("SELECT flight_id, COUNT(*) c FROM warehouse_movements WHERE flight_id IN (187,188,189,190) GROUP BY flight_id, movement_type, warehouse_id, zayavka_id HAVING COUNT(*)>1")->fetchAll(PDO::FETCH_ASSOC);
echo count($d) . " duplicate groups\n";
echo "DONE\n";
