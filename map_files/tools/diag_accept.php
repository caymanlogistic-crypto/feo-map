<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

echo "=== TEST ACCEPT flights ===\n";
$stmt = $pdo->query("SELECT id, status, route_type, unload_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, zayavki_count, actual_start_date, actual_end_date, driver_id, cost, comment FROM flights WHERE comment LIKE 'TEST ACCEPT%' ORDER BY id ASC");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
echo count($rows) . " flights found.\n";
foreach ($rows as $r) {
    echo "#{$r['id']} status={$r['status']} route_type={$r['route_type']} unload={$r['unload_type']} src_wh={$r['source_warehouse_id']} dst_wh={$r['destination_warehouse_id']} zids={$r['zayavki_ids']} zcnt={$r['zayavki_count']} actual_start=" . ($r['actual_start_date'] ?? 'NULL') . " actual_end=" . ($r['actual_end_date'] ?? 'NULL') . " drv={$r['driver_id']} cost={$r['cost']} comment={$r['comment']}\n";
}

echo "\n=== Warehouse movements ===\n";
$ids = array_column($rows, 'id');
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt2 = $pdo->prepare("SELECT id, flight_id, movement_type, warehouse_id, source_warehouse_id, destination_warehouse_id, zayavka_id, fkko_code, mass_netto, mass_brutto, volume, status, comment FROM warehouse_movements WHERE flight_id IN ({$placeholders}) ORDER BY flight_id, zayavka_id, movement_type, id");
    $stmt2->execute($ids);
    $wms = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo count($wms) . " movements found.\n";
    foreach ($wms as $w) {
        echo "  flight={$w['flight_id']} type={$w['movement_type']} wh={$w['warehouse_id']} zid={$w['zayavka_id']} mass={$w['mass_netto']} comment={$w['comment']}\n";
    }
    $stmt3 = $pdo->prepare("SELECT flight_id, movement_type, warehouse_id, zayavka_id, COUNT(*) AS cnt FROM warehouse_movements WHERE flight_id IN ({$placeholders}) GROUP BY flight_id, movement_type, warehouse_id, zayavka_id HAVING COUNT(*) > 1");
    $stmt3->execute($ids);
    $dups = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    echo "Duplicates: " . count($dups) . "\n";
    foreach ($dups as $d) {
        echo "  DUPLICATE flight={$d['flight_id']} type={$d['movement_type']} wh={$d['warehouse_id']} zid={$d['zayavka_id']} cnt={$d['cnt']}\n";
    }
}

echo "\n=== MAX logs ===\n";
$tables = ['max_send_log', 'max_logs'];
foreach ($tables as $tbl) {
    $exists = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $tbl) . "'");
    if (!$exists || !$exists->fetchColumn()) { echo "Table {$tbl} not found.\n"; continue; }
    $cols = $pdo->query("SHOW COLUMNS FROM `" . str_replace("`", "``", $tbl) . "`");
    $colList = $cols ? $cols->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!in_array('event_key', $colList)) { echo "Table {$tbl} has no event_key.\n"; continue; }
    $stmt4 = $pdo->query("SELECT event_key, success, created_at FROM `" . str_replace("`", "``", $tbl) . "` ORDER BY id DESC LIMIT 20");
    $logs = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    echo "Last 20 from {$tbl}:\n";
    foreach ($logs as $l) {
        $ok = (int)($l['success'] ?? 0) === 1 ? 'OK' : 'ERR';
        echo "  [{$ok}] {$l['event_key']} at {$l['created_at']}\n";
    }
}

echo "\n=== TEST driver ===\n";
$stmt5 = $pdo->query("SELECT id, full_name, vehicle_make_plate FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%ТЕСТ%' ORDER BY id DESC LIMIT 3");
$drvs = $stmt5->fetchAll(PDO::FETCH_ASSOC);
foreach ($drvs as $d) {
    echo "  Driver #{$d['id']}: {$d['full_name']} / {$d['vehicle_make_plate']}\n";
}

echo "\n=== DONE ===\n";
