<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/warehouse_movements.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR\n"; exit(1); }

echo "=== SAFE DELETE TEST ===\n\n";

// Find TEST flight with WM
$r = $pdo->query("SELECT f.id, f.comment, COUNT(w.id) AS wm FROM flights f LEFT JOIN warehouse_movements w ON f.id=w.flight_id WHERE f.comment LIKE '%TEST%' GROUP BY f.id HAVING wm > 0 ORDER BY f.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$r) { echo "FAIL: No TEST flight with warehouse_movements found.\n"; exit(1); }
$fid = (int)$r['id'];
echo "Found TEST flight #{$fid}: comment={$r['comment']}, WM={$r['wm']}\n\n";

// Show preview
$wmRows = $pdo->query("SELECT id, movement_type, warehouse_id, zayavka_id, mass_netto FROM warehouse_movements WHERE flight_id={$fid} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "Warehouse movements to delete (" . count($wmRows) . "):\n";
foreach ($wmRows as $w) { echo "  #{$w['id']} {$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} mass={$w['mass_netto']}\n"; }

// Delete (WM first, then flight)
$delWm = $pdo->exec("DELETE FROM warehouse_movements WHERE flight_id={$fid}");
$delFl = $pdo->exec("DELETE FROM flights WHERE id={$fid}");
echo "\nDeleted: WM={$delWm}, FL={$delFl}\n";

// Verify
$wmAfter = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id={$fid}")->fetchColumn();
$flAfter = (int)$pdo->query("SELECT COUNT(*) FROM flights WHERE id={$fid}")->fetchColumn();
echo "Verification: WM={$wmAfter}, FL={$flAfter}\n";
echo ($wmAfter===0 && $flAfter===0) ? "PASS\n" : "FAIL\n";
