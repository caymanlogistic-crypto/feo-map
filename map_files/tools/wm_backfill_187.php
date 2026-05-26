<?php
/**
 * TEMPORARY backfill endpoint — runs createWarehouseMovementsForCompletedFlight for #187.
 * DELETE after verification.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/Support/warehouse_movements.php';

header('Content-Type: application/json; charset=utf-8');

$flightId = 187;

// Verify it's a test route
$flight = $pdo->query("SELECT id, status, route_type, destination_warehouse_id, zayavki_ids, comment FROM flights WHERE id = {$flightId}")->fetch(PDO::FETCH_ASSOC);
if (!$flight) {
    echo json_encode(['success' => false, 'error' => 'Flight not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'flight' => $flight,
    'wm_before' => (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = {$flightId}")->fetchColumn(),
    'backfill' => createWarehouseMovementsForCompletedFlight($pdo, $flightId),
    'wm_after' => (int)$pdo->query("SELECT COUNT(*) FROM warehouse_movements WHERE flight_id = {$flightId}")->fetchColumn(),
    'movements' => $pdo->query("SELECT id, movement_type, warehouse_id, zayavka_id, fkko_code, ROUND(mass_netto,3) mass_netto, status FROM warehouse_movements WHERE flight_id = {$flightId}")->fetchAll(PDO::FETCH_ASSOC),
], JSON_UNESCAPED_UNICODE);
