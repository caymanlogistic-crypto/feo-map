<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/bootstrap.php';
require_once $baseDir . '/save_planned_route.php';
require_once $baseDir . '/Support/warehouse_movements.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "ERROR: No PDO connection.\n";
    exit(1);
}

$testPrefix = 'TEST ACCEPT';

function dbSelect(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) return [];
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function dbExec(PDO $pdo, string $sql, array $params = []): bool {
    $stmt = $pdo->prepare($sql);
    return $stmt && $stmt->execute($params);
}

function findManagerId(PDO $pdo): int {
    $roleCol = null;
    $cols = dbSelect($pdo, 'SHOW COLUMNS FROM users');
    $colMap = [];
    foreach ($cols as $c) { $colMap[(string)($c['Field'] ?? '')] = true; }
    foreach (['Роль', 'role', 'user_role', 'type'] as $cand) {
        if (isset($colMap[$cand])) { $roleCol = $cand; break; }
    }
    if ($roleCol === null) {
        $row = dbSelect($pdo, 'SELECT id FROM users LIMIT 1');
        return !empty($row) ? (int)$row[0]['id'] : 0;
    }
    $rows = dbSelect($pdo, 'SELECT id FROM users WHERE LOWER(TRIM(' . quoteIdent($roleCol) . ')) = :role LIMIT 1', [':role' => 'logist']);
    if (!empty($rows)) return (int)$rows[0]['id'];
    $rows = dbSelect($pdo, 'SELECT id FROM users LIMIT 1');
    return !empty($rows) ? (int)$rows[0]['id'] : 0;
}

function findAnyZayavkaIds(PDO $pdo, int $count = 2): array {
    $rows = dbSelect($pdo, 'SELECT zayavka_id FROM feo ORDER BY zayavka_id DESC LIMIT ' . max(1, (int)$count));
    $ids = [];
    foreach ($rows as $r) {
        $id = (int)($r['zayavka_id'] ?? 0);
        if ($id > 0) $ids[] = (string)$id;
    }
    return $ids;
}

function findActiveWarehouse(PDO $pdo, ?int $excludeId = null): ?int {
    $colMap = [];
    $cols = dbSelect($pdo, 'SHOW COLUMNS FROM warehouses');
    foreach ($cols as $c) { $colMap[(string)($c['Field'] ?? '')] = true; }
    $sql = 'SELECT id FROM warehouses WHERE id > 0';
    foreach (['is_active', 'active', 'enabled'] as $ac) {
        if (isset($colMap[$ac])) {
            $sql .= ' AND ' . quoteIdent($ac) . ' = 1';
            break;
        }
    }
    if ($excludeId !== null) $sql .= ' AND id <> ' . (int)$excludeId;
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $rows = dbSelect($pdo, $sql);
    if (!empty($rows)) return (int)$rows[0]['id'];
    return null;
}

function findOrCreateTestDriver(PDO $pdo): int {
    $rows = dbSelect($pdo, "SELECT id, full_name FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%\u0422\u0415\u0421\u0422%' ORDER BY id DESC LIMIT 1");
    if (!empty($rows)) {
        echo "  [DRIVER] Found existing TEST driver: id={$rows[0]['id']}, name={$rows[0]['full_name']}\n";
        return (int)$rows[0]['id'];
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO drivers (full_name, vehicle_make_plate) VALUES (:name, :plate)");
        $stmt->execute([':name' => 'TEST ТЕСТ Acceptance Driver', ':plate' => 'T999TEST']);
        $newId = (int)$pdo->lastInsertId();
        echo "  [DRIVER] Created TEST driver: id={$newId}\n";
        return $newId;
    } catch (Throwable $e) {
        echo "  [DRIVER] Failed to create: {$e->getMessage()}\n";
        return 0;
    }
}

function directTransition(PDO $pdo, array $flight, string $targetStatus, ?string $dateValue = null): array {
    $flightId = (int)($flight['id'] ?? 0);
    $statusBefore = (string)($flight['status'] ?? '');

    $deny = assertTransitionAllowed($flight, $targetStatus);
    if ($deny !== null) {
        return ['success' => false, 'message' => $deny];
    }

    if ($targetStatus === 'started') {
        $actualTs = strtotime($dateValue ?? date('Y-m-d H:i:s'));
        if ($actualTs === false) return ['success' => false, 'message' => 'Некорректная дата'];
        dbExec($pdo, 'UPDATE flights SET status = :status, actual_start_date = :actual_start WHERE id = :id LIMIT 1', [
            ':status' => 'started',
            ':actual_start' => date('Y-m-d H:i:s', $actualTs),
            ':id' => $flightId,
        ]);
    } elseif ($targetStatus === 'completed') {
        $actualEndTs = strtotime($dateValue ?? date('Y-m-d H:i:s'));
        if ($actualEndTs === false) return ['success' => false, 'message' => 'Некорректная дата'];
        dbExec($pdo, 'UPDATE flights SET status = :status, actual_end_date = :actual_end WHERE id = :id LIMIT 1', [
            ':status' => 'completed',
            ':actual_end' => date('Y-m-d H:i:s', $actualEndTs),
            ':id' => $flightId,
        ]);
        createWarehouseMovementsForCompletedFlight($pdo, $flightId);
    } elseif ($targetStatus === 'found' && $statusBefore === 'started') {
        dbExec($pdo, 'UPDATE flights SET status = :status, actual_start_date = NULL WHERE id = :id LIMIT 1', [
            ':status' => 'found',
            ':id' => $flightId,
        ]);
    } else {
        dbExec($pdo, 'UPDATE flights SET status = :status WHERE id = :id LIMIT 1', [
            ':status' => $targetStatus,
            ':id' => $flightId,
        ]);
    }
    return ['success' => true, 'message' => "Transition to {$targetStatus} OK"];
}

function loadFlight(PDO $pdo, int $flightId): ?array {
    return loadFlightSnapshot($pdo, $flightId);
}

function printFlightRow(PDO $pdo, int $flightId): void {
    $cols = ['id','status','route_type','unload_type','source_warehouse_id','destination_warehouse_id','zayavki_ids','zayavki_count','actual_start_date','actual_end_date','driver_id','cost','comment'];
    $rows = dbSelect($pdo, 'SELECT ' . implode(',', array_map(function($c) { return quoteIdent($c); }, $cols)) . ' FROM flights WHERE id = :id', [':id' => $flightId]);
    if (empty($rows)) { echo "  Flight #{$flightId} NOT FOUND\n"; return; }
    $r = $rows[0];
    echo "  Flight #{$flightId}:\n";
    foreach ($cols as $c) {
        $val = $r[$c] ?? null;
        echo "    {$c}: " . ($val === null ? 'NULL' : (string)$val) . "\n";
    }
}

function printWarehouseMovements(PDO $pdo, int $flightId): void {
    $cols = ['id','movement_type','warehouse_id','source_warehouse_id','destination_warehouse_id','flight_id','zayavka_id','fkko_code','mass_netto','mass_brutto','volume','status','comment'];
    $rows = dbSelect($pdo,
        'SELECT ' . implode(',', array_map(function($c) { return quoteIdent($c); }, $cols)) . ' FROM warehouse_movements WHERE flight_id = :id ORDER BY zayavka_id, movement_type, id',
        [':id' => $flightId]
    );
    echo "  warehouse_movements for flight #{$flightId}: " . count($rows) . " rows\n";
    foreach ($rows as $r) {
        echo "    movement_type={$r['movement_type']}, warehouse_id={$r['warehouse_id']}, zayavka_id={$r['zayavka_id']}, mass_netto={$r['mass_netto']}, comment={$r['comment']}\n";
    }
    $dupRows = dbSelect($pdo,
        'SELECT flight_id, movement_type, warehouse_id, zayavka_id, COUNT(*) AS cnt FROM warehouse_movements WHERE flight_id = :id GROUP BY flight_id, movement_type, warehouse_id, zayavka_id HAVING COUNT(*) > 1',
        [':id' => $flightId]
    );
    echo "  Duplicates: " . count($dupRows) . " rows\n";
    foreach ($dupRows as $d) {
        echo "    DUPLICATE: movement_type={$d['movement_type']}, warehouse_id={$d['warehouse_id']}, zayavka_id={$d['zayavka_id']}, cnt={$d['cnt']}\n";
    }
}

function checkMaxLogs(PDO $pdo, int $flightId): void {
    $tables = ['max_send_log', 'max_logs'];
    foreach ($tables as $table) {
        $exists = dbSelect($pdo, "SHOW TABLES LIKE '" . str_replace("'", "''", $table) . "'");
        if (empty($exists)) continue;
        $cols = dbSelect($pdo, 'SHOW COLUMNS FROM ' . quoteIdent($table));
        $hasEventKey = false;
        foreach ($cols as $c) { if (($c['Field'] ?? '') === 'event_key') { $hasEventKey = true; break; } }
        if (!$hasEventKey) continue;
        $sql = 'SELECT event_key, success, created_at FROM ' . quoteIdent($table) . ' WHERE message_text LIKE :flight ORDER BY id DESC LIMIT 10';
        $rows = dbSelect($pdo, $sql, [':flight' => "%#{$flightId}%"]);
        echo "  MAX log [{$table}] for flight #{$flightId}: " . count($rows) . " entries\n";
        foreach ($rows as $r) {
            $ok = (int)($r['success'] ?? 0) === 1 ? 'OK' : 'ERR';
            echo "    [{$ok}] event_key={$r['event_key']}\n";
        }
    }
}

// === PHASE 0 ===
echo "=== ACCEPTANCE TEST: 4 ROUTE TYPES (direct PHP) ===\n\n";

echo "PHASE 0: Resource discovery\n";
$managerId = findManagerId($pdo);
echo "  Manager ID: {$managerId}\n";
if ($managerId <= 0) { echo "FAIL: No manager.\n"; exit(1); }

$zayavkaIds = findAnyZayavkaIds($pdo, 3);
echo "  Zayavka IDs: " . implode(',', $zayavkaIds) . "\n";
if (count($zayavkaIds) < 1) { echo "FAIL: No zayavka IDs.\n"; exit(1); }

$wh1 = findActiveWarehouse($pdo);
$wh2 = findActiveWarehouse($pdo, $wh1);
echo "  Warehouse 1: " . ($wh1 ?? 'NONE') . "\n";
echo "  Warehouse 2: " . ($wh2 ?? 'NONE') . "\n";

$driverId = findOrCreateTestDriver($pdo);

$managerColumn = resolveFlightsManagerColumn($pdo);
$quotedMC = quoteIdent($managerColumn ?? 'assigned_manager_id');

$testRoutes = [
    [
        'name' => 'TEST ACCEPT generator_to_utilizer',
        'route_type' => 'generator_to_utilizer',
        'source_warehouse_id' => null,
        'destination_warehouse_id' => null,
        'unload_type' => 'OO',
    ],
    [
        'name' => 'TEST ACCEPT generator_to_warehouse',
        'route_type' => 'generator_to_warehouse',
        'source_warehouse_id' => null,
        'destination_warehouse_id' => $wh1,
        'unload_type' => 'SKLAD',
    ],
    [
        'name' => 'TEST ACCEPT warehouse_to_warehouse',
        'route_type' => 'warehouse_to_warehouse',
        'source_warehouse_id' => $wh1,
        'destination_warehouse_id' => $wh2,
        'unload_type' => 'SKLAD',
    ],
    [
        'name' => 'TEST ACCEPT warehouse_to_utilizer',
        'route_type' => 'warehouse_to_utilizer',
        'source_warehouse_id' => $wh1,
        'destination_warehouse_id' => null,
        'unload_type' => 'OO',
    ],
];

$createdFlightIds = [];

// === PHASE 1: Create via direct INSERT using штатные функции ===
echo "\nPHASE 1: Create 4 test flights (direct DB, штатные функции)\n";
foreach ($testRoutes as $idx => $route) {
    $label = $route['name'];
    $quotedMC = quoteIdent($managerColumn ?? 'assigned_manager_id');

    // Use штатные normalize functions
    $rt = normalizeRouteType($route['route_type'], $route['unload_type']);
    $ult = resolveUnloadTypeByRouteType($rt);
    $sw = normalizeWarehouseId($pdo, $route['source_warehouse_id']);
    $dw = normalizeWarehouseId($pdo, $route['destination_warehouse_id']);
    $zayavkiList = array_slice($zayavkaIds, 0, 2);

    $sql = "INSERT INTO flights (status, comment, cost, unload_type, route_type, source_warehouse_id, destination_warehouse_id, zayavki_ids, zayavki_count, {$quotedMC}, planned_start_date_from, planned_start_date_to, driver_id, block_date)
            VALUES (:status, :comment, :cost, :unload_type, :route_type, :source_warehouse_id, :destination_warehouse_id, :zayavki_ids, :count, :assigned_manager_id, :planned_from, :planned_to, :driver_id, NOW())";
    $params = [
        ':status' => 'planned_route',
        ':comment' => $label,
        ':cost' => 1000.0,
        ':unload_type' => $ult,
        ':route_type' => $rt,
        ':source_warehouse_id' => $sw,
        ':destination_warehouse_id' => $dw,
        ':zayavki_ids' => implode(',', $zayavkiList),
        ':count' => count($zayavkiList),
        ':assigned_manager_id' => $managerId,
        ':planned_from' => date('Y-m-d H:i:s', strtotime('+2 days')),
        ':planned_to' => date('Y-m-d H:i:s', strtotime('+3 days')),
        ':driver_id' => ($idx === 0 ? $driverId : 0),
    ];
    echo "  Creating: {$label}\n";
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt || !$stmt->execute($params)) {
            echo "    ERROR: Insert failed\n";
            $errInfo = $stmt ? $stmt->errorInfo() : ['?'];
            echo "    ErrorInfo: " . implode(' | ', $errInfo) . "\n";
            continue;
        }
        $newId = (int)$pdo->lastInsertId();
        echo "    Created: id={$newId}\n";
        $createdFlightIds[] = $newId;
    } catch (Throwable $e) {
        echo "    EXCEPTION: {$e->getMessage()}\n";
    }
}

if (count($createdFlightIds) < 4) {
    echo "WARN: Only " . count($createdFlightIds) . "/4 flights created.\n";
}

echo "\nCreated flight IDs: " . implode(', ', $createdFlightIds) . "\n";

// === PHASE 2: SELECT after creation ===
echo "\nPHASE 2: SELECT after creation\n";
foreach ($createdFlightIds as $fid) {
    printFlightRow($pdo, $fid);
}

// === PHASE 3: Transitions ===
echo "\nPHASE 3: Status transitions\n";

foreach ($createdFlightIds as $fid) {
    echo "\n--- Flight #{$fid} ---\n";
    $flight = loadFlight($pdo, $fid);
    if (!$flight) { echo "  Flight not found, skipping.\n"; continue; }

    // planned_route → found
    echo "  [1] planned_route → found\n";
    $r = directTransition($pdo, $flight, 'found');
    echo "    success={$r['success']}, message={$r['message']}\n";
    $flight = loadFlight($pdo, $fid);
    printFlightRow($pdo, $fid);

    // found → started (with date)
    $startDate = date('Y-m-d H:i:s', strtotime('-1 hour'));
    echo "  [2] found → started (date={$startDate})\n";
    $r = directTransition($pdo, $flight, 'started', $startDate);
    echo "    success={$r['success']}, message={$r['message']}\n";
    $flight = loadFlight($pdo, $fid);
    printFlightRow($pdo, $fid);

    // started → found (rollback)
    echo "  [3] started → found (ROLLBACK) — actual_start_date should be cleared\n";
    $r = directTransition($pdo, $flight, 'found');
    echo "    success={$r['success']}, message={$r['message']}\n";
    $flight = loadFlight($pdo, $fid);
    printFlightRow($pdo, $fid);

    // found → started again
    $startDate2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
    echo "  [4] found → started (date={$startDate2})\n";
    $flight = loadFlight($pdo, $fid);
    $r = directTransition($pdo, $flight, 'started', $startDate2);
    echo "    success={$r['success']}, message={$r['message']}\n";
    $flight = loadFlight($pdo, $fid);
    printFlightRow($pdo, $fid);

    // started → completed
    $endDate = date('Y-m-d H:i:s');
    echo "  [5] started → completed (end_date={$endDate})\n";
    $flight = loadFlight($pdo, $fid);
    $r = directTransition($pdo, $flight, 'completed', $endDate);
    echo "    success={$r['success']}, message={$r['message']}\n";
    printFlightRow($pdo, $fid);
}

// === PHASE 4: warehouse_movements ===
echo "\nPHASE 4: warehouse_movements\n";
foreach ($createdFlightIds as $fid) {
    printWarehouseMovements($pdo, $fid);
}

// === PHASE 5: MAX logs ===
echo "\nPHASE 5: MAX logs\n";
foreach ($createdFlightIds as $fid) {
    checkMaxLogs($pdo, $fid);
}

// === PHASE 6: Driver check ===
echo "\nPHASE 6: Driver check\n";
$driverFlight = $createdFlightIds[0];
$rows = dbSelect($pdo, 'SELECT driver_id, status, route_type FROM flights WHERE id = :id', [':id' => $driverFlight]);
if (!empty($rows)) {
    echo "  Flight #{$driverFlight}: status={$rows[0]['status']}, driver_id={$rows[0]['driver_id']}, route_type={$rows[0]['route_type']}\n";
}

echo "\n=== ACCEPTANCE TEST COMPLETE ===\n";
echo "Test flight IDs: " . implode(', ', $createdFlightIds) . "\n";
