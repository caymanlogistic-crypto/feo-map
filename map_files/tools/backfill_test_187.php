<?php

/**
 * ONE-TIME BACKFILL for TEST route #187 only.
 * Calls createWarehouseMovementsForCompletedFlight through the standard bootstrap.
 * Usage: /usr/bin/php8.4 map_files/tools/backfill_test_187.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "FATAL: No database connection.\n");
    exit(1);
}

$flightId = 187;

echo "=== BACKFILL ROUTE #187 ===\n\n";

// Show current state
$flight = $pdo->query("SELECT id, status, route_type, destination_warehouse_id, zayavki_ids, comment FROM flights WHERE id = {$flightId}")->fetch(PDO::FETCH_ASSOC);
if (!$flight) {
    echo "FAIL: Flight #187 not found.\n";
    exit(1);
}
echo "Flight: id={$flight['id']} status={$flight['status']} route_type={$flight['route_type']} comment={$flight['comment']}\n";

// SAFETY CHECKS
$commentLower = mb_strtolower(trim((string)($flight['comment'] ?? '')), 'UTF-8');
$hasTestWord = (mb_strpos($commentLower, 'тест', 0, 'UTF-8') !== false);

if (!$hasTestWord) {
    echo "FAIL: Flight #187 comment does not contain 'ТЕСТ'. Aborting.\n";
    exit(1);
}
if (strtolower(trim((string)($flight['route_type'] ?? ''))) !== 'generator_to_warehouse') {
    echo "FAIL: Flight #187 route_type is not generator_to_warehouse. Aborting.\n";
    exit(1);
}
if ((int)($flight['destination_warehouse_id'] ?? 0) <= 0) {
    echo "FAIL: Flight #187 destination_warehouse_id is empty. Aborting.\n";
    exit(1);
}
if (trim((string)($flight['zayavki_ids'] ?? '')) === '') {
    echo "FAIL: Flight #187 zayavki_ids is empty. Aborting.\n";
    exit(1);
}
echo "Safety checks PASSED.\n\n";

$wmCount = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = {$flightId}")->fetchColumn();
echo "Current WM count: {$wmCount}\n\n";

if ($wmCount > 0) {
    echo "SKIP: Movements already exist for flight #187. No backfill needed.\n";
    exit(0);
}

// Run backfill
echo "Calling createWarehouseMovementsForCompletedFlight({$flightId})...\n";
$result = createWarehouseMovementsForCompletedFlight($pdo, $flightId);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// Show after state
$wmCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = {$flightId}")->fetchColumn();
echo "After backfill WM count: {$wmCountAfter}\n\n";

if ($wmCountAfter > 0) {
    echo "=== MOVEMENTS ===\n";
    $rows = $pdo->query("SELECT id, movement_type, warehouse_id, source_warehouse_id, destination_warehouse_id, flight_id, zayavka_id, fkko_code, ROUND(mass_netto,3) mass_netto, ROUND(mass_brutto,3) mass_brutto, ROUND(volume,3) volume, movement_date, status, comment FROM warehouse_movements WHERE flight_id = {$flightId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        foreach ($row as $k => $v) {
            echo "  {$k} = {$v}\n";
        }
        echo "\n";
    }
}

echo "=== DONE ===\n";
