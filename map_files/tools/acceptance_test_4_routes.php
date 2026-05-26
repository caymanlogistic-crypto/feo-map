<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/bootstrap.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "ERROR: No PDO connection.\n";
    exit(1);
}

$baseUrl = 'http://spugovxsim.temp.swtest.ru/fregat/feo/map_files/save_planned_route.php';
$testPrefix = 'TEST ACCEPT';

function apiCall(string $url, array $payload): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['success' => false, 'message' => 'HTTP request failed'];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'Invalid JSON response', 'raw' => $raw];
}

function dbSelect(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    if (!$stmt || !$stmt->execute($params)) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function quoteIdent(string $name): string {
    return '`' . str_replace('`', '``', $name) . '`';
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
    if ($excludeId !== null) {
        $sql .= ' AND id <> ' . (int)$excludeId;
    }
    $sql .= ' ORDER BY id ASC LIMIT 1';
    $rows = dbSelect($pdo, $sql);
    if (!empty($rows)) return (int)$rows[0]['id'];
    if ($excludeId !== null) {
        $sql2 = 'SELECT id FROM warehouses WHERE id <> ' . (int)$excludeId . ' AND id > 0 ORDER BY id ASC LIMIT 1';
        $rows2 = dbSelect($pdo, $sql2);
        if (!empty($rows2)) return (int)$rows2[0]['id'];
    }
    return null;
}

function findOrCreateTestDriver(PDO $pdo): int {
    $rows = dbSelect($pdo, "SELECT id, full_name FROM drivers WHERE full_name LIKE '%TEST%' OR full_name LIKE '%ТЕСТ%' ORDER BY id DESC LIMIT 1");
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

function validateEmptyStartDate(PDO $pdo, string $baseUrl, int $flightId): bool {
    echo "  [VALIDATE] Testing empty start date rejection...\n";
    $result = apiCall($baseUrl, [
        'action' => 'transition',
        'id' => $flightId,
        'target_status' => 'started',
        'actual_start_date' => '',
    ]);
    $blocked = !($result['success'] ?? false);
    $msg = $result['message'] ?? '(no message)';
    echo "  [VALIDATE] Empty date: success={$result['success']}, message={$msg}\n";
    return $blocked;
}

function transitionFlight(PDO $pdo, string $baseUrl, int $flightId, string $targetStatus, string $dateKey, string $dateValue): array {
    $payload = [
        'action' => 'transition',
        'id' => $flightId,
        'target_status' => $targetStatus,
    ];
    if ($dateValue !== '') {
        $payload[$dateKey] = $dateValue;
    }
    $result = apiCall($baseUrl, $payload);
    return $result;
}

function printFlightRow(PDO $pdo, int $flightId): void {
    $cols = ['id','status','route_type','unload_type','source_warehouse_id','destination_warehouse_id','zayavki_ids','zayavki_count','actual_start_date','actual_end_date','driver_id','cost','comment'];
    $rows = dbSelect($pdo, 'SELECT ' . implode(',', $cols) . ' FROM flights WHERE id = :id', [':id' => $flightId]);
    if (empty($rows)) {
        echo "  Flight #{$flightId} NOT FOUND\n";
        return;
    }
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
        'SELECT ' . implode(',', $cols) . ' FROM warehouse_movements WHERE flight_id = :id ORDER BY zayavka_id, movement_type, id',
        [':id' => $flightId]
    );
    echo "  warehouse_movements for flight #{$flightId}: " . count($rows) . " rows\n";
    foreach ($rows as $r) {
        echo "    movement_type={$r['movement_type']}, warehouse_id={$r['warehouse_id']}, zayavka_id={$r['zayavka_id']}, mass_netto={$r['mass_netto']}\n";
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
        $hasCreatedAt = false;
        foreach ($cols as $c) {
            $f = (string)($c['Field'] ?? '');
            if ($f === 'event_key') $hasEventKey = true;
            if ($f === 'created_at') $hasCreatedAt = true;
        }
        if (!$hasEventKey) continue;
        $sql = 'SELECT event_key, message_text, success, created_at FROM ' . quoteIdent($table) . ' WHERE message_text LIKE :flight ORDER BY id DESC LIMIT 5';
        $rows = dbSelect($pdo, $sql, [':flight' => "%#{$flightId}%"]);
        echo "  MAX log [{$table}] for flight #{$flightId}: " . count($rows) . " entries\n";
        foreach ($rows as $r) {
            $ok = (int)($r['success'] ?? 0) === 1 ? 'OK' : 'ERR';
            $key = $r['event_key'] ?? '?';
            $msg = mb_substr((string)($r['message_text'] ?? ''), 0, 80);
            echo "    [{$ok}] {$key}: {$msg}\n";
        }
    }
}

// === PHASE 0: Discover resources ===
echo "=== ACCEPTANCE TEST: 4 ROUTE TYPES ===\n\n";

echo "PHASE 0: Resource discovery\n";
$managerId = findManagerId($pdo);
echo "  Manager ID: {$managerId}\n";
if ($managerId <= 0) {
    echo "FAIL: No manager found.\n";
    exit(1);
}

$zayavkaIds = findAnyZayavkaIds($pdo, 3);
echo "  Zayavka IDs: " . implode(',', $zayavkaIds) . "\n";
if (count($zayavkaIds) < 1) {
    echo "FAIL: No zayavka IDs found.\n";
    exit(1);
}

$wh1 = findActiveWarehouse($pdo);
$wh2 = findActiveWarehouse($pdo, $wh1);
echo "  Warehouse 1: " . ($wh1 ?? 'NONE') . "\n";
echo "  Warehouse 2: " . ($wh2 ?? 'NONE') . "\n";
if ($wh1 === null) {
    echo "WARN: No warehouse found. Warehouse routes will fail.\n";
}

$driverId = findOrCreateTestDriver($pdo);

$testRoutes = [
    [
        'name' => 'TEST ACCEPT generator_to_utilizer',
        'route_type' => 'generator_to_utilizer',
        'source_warehouse_id' => null,
        'destination_warehouse_id' => null,
    ],
    [
        'name' => 'TEST ACCEPT generator_to_warehouse',
        'route_type' => 'generator_to_warehouse',
        'source_warehouse_id' => null,
        'destination_warehouse_id' => $wh1,
    ],
    [
        'name' => 'TEST ACCEPT warehouse_to_warehouse',
        'route_type' => 'warehouse_to_warehouse',
        'source_warehouse_id' => $wh1,
        'destination_warehouse_id' => $wh2,
    ],
    [
        'name' => 'TEST ACCEPT warehouse_to_utilizer',
        'route_type' => 'warehouse_to_utilizer',
        'source_warehouse_id' => $wh1,
        'destination_warehouse_id' => null,
    ],
];

$createdFlightIds = [];

// === PHASE 1: Create flights ===
echo "\nPHASE 1: Create 4 test flights\n";
foreach ($testRoutes as $idx => $route) {
    $label = $route['name'];
    $payload = [
        'action' => 'save',
        'id' => 0,
        'name' => $label,
        'comment' => $label,
        'route_type' => $route['route_type'],
        'source_warehouse_id' => $route['source_warehouse_id'] ?? null,
        'destination_warehouse_id' => $route['destination_warehouse_id'] ?? null,
        'zayavki_ids' => implode(',', array_slice($zayavkaIds, 0, 2)),
        'driver_id' => ($idx === 0 ? $driverId : 0),
        'assigned_manager_id' => $managerId,
        'planned_start_date_from' => date('Y-m-d H:i:s', strtotime('+2 days')),
        'planned_start_date_to' => date('Y-m-d H:i:s', strtotime('+3 days')),
        'cost' => '1000',
    ];
    echo "  Creating: {$label}\n";
    $result = apiCall($baseUrl, $payload);
    echo "    success={$result['success']}, id=" . ($result['id'] ?? 'N/A') . ", message={$result['message']}\n";
    if (!empty($result['id'])) {
        $createdFlightIds[] = (int)$result['id'];
    } else {
        echo "    ERROR creating flight: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

if (count($createdFlightIds) < 4) {
    echo "FAIL: Not all flights created. Got " . count($createdFlightIds) . "/4\n";
    exit(1);
}

echo "\nCreated flight IDs: " . implode(', ', $createdFlightIds) . "\n";

// === PHASE 2: SELECT after creation ===
echo "\nPHASE 2: SELECT after creation\n";
foreach ($createdFlightIds as $fid) {
    printFlightRow($pdo, $fid);
}

// === PHASE 3: Validate empty start date ===
echo "\nPHASE 3: Validate empty start date\n";
$firstId = $createdFlightIds[0];
$blocked = validateEmptyStartDate($pdo, $baseUrl, $firstId);
if ($blocked) {
    echo "  PASS: Empty start date correctly blocked.\n";
} else {
    echo "  WARN: Empty start date was NOT blocked by backend.\n";
}

// === PHASE 4: Transition all → found, → started, → found (rollback), → started, → completed ===
echo "\nPHASE 4: Status transitions\n";

foreach ($createdFlightIds as $fid) {
    echo "\n--- Flight #{$fid} ---\n";

    // planned_route → found
    echo "  [1] planned_route → found\n";
    $result = transitionFlight($pdo, $baseUrl, $fid, 'found', 'actual_start_date', '');
    echo "    success={$result['success']}, message={$result['message']}\n";
    printFlightRow($pdo, $fid);

    // found → started (with date)
    $startDate = date('Y-m-d H:i:s', strtotime('-1 hour'));
    echo "  [2] found → started (date={$startDate})\n";
    $result = transitionFlight($pdo, $baseUrl, $fid, 'started', 'actual_start_date', $startDate);
    echo "    success={$result['success']}, message={$result['message']}, notify_success={$result['notify_success']}\n";
    printFlightRow($pdo, $fid);

    // started → found (rollback)
    echo "  [3] started → found (ROLLBACK)\n";
    $result = transitionFlight($pdo, $baseUrl, $fid, 'found', 'actual_start_date', '');
    echo "    success={$result['success']}, message={$result['message']}, notify_success={$result['notify_success']}\n";
    printFlightRow($pdo, $fid);
    echo "    CHECK: actual_start_date should be NULL after rollback.\n";

    // found → started again
    $startDate2 = date('Y-m-d H:i:s', strtotime('-2 hours'));
    echo "  [4] found → started (date={$startDate2})\n";
    $result = transitionFlight($pdo, $baseUrl, $fid, 'started', 'actual_start_date', $startDate2);
    echo "    success={$result['success']}, message={$result['message']}, notify_success={$result['notify_success']}\n";
    printFlightRow($pdo, $fid);

    // started → completed
    $endDate = date('Y-m-d H:i:s');
    echo "  [5] started → completed (end_date={$endDate})\n";
    $result = transitionFlight($pdo, $baseUrl, $fid, 'completed', 'actual_end_date', $endDate);
    echo "    success={$result['success']}, message={$result['message']}, notify_success={$result['notify_success']}\n";
    if (isset($result['warehouse_movements'])) {
        $wm = $result['warehouse_movements'];
        echo "    warehouse_movements: created={$wm['created']}, skipped={$wm['skipped']}, errors=" . count($wm['errors'] ?? []) . "\n";
    }
    printFlightRow($pdo, $fid);
}

// === PHASE 5: warehouse_movements ===
echo "\nPHASE 5: warehouse_movements\n";
foreach ($createdFlightIds as $fid) {
    printWarehouseMovements($pdo, $fid);
}

// === PHASE 6: MAX logs ===
echo "\nPHASE 6: MAX logs\n";
foreach ($createdFlightIds as $fid) {
    checkMaxLogs($pdo, $fid);
}

// === PHASE 7: Driver check ===
echo "\nPHASE 7: Driver check\n";
$driverFlight = $createdFlightIds[0];
$rows = dbSelect($pdo, 'SELECT driver_id FROM flights WHERE id = :id', [':id' => $driverFlight]);
if (!empty($rows)) {
    echo "  Flight #{$driverFlight} driver_id: {$rows[0]['driver_id']}\n";
}

echo "\n=== ACCEPTANCE TEST COMPLETE ===\n";
echo "Test flight IDs: " . implode(', ', $createdFlightIds) . "\n";
