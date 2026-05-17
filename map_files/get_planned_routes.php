<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function safeJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeRouteRow(array $row): array
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '') {
        $name = 'Рейс #' . (int)($row['id'] ?? 0);
    }

    return [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'status' => (string)($row['status'] ?? ''),
        'name' => $name,
        'route_title' => $name,
        'cost' => $row['cost'] ?? null,
        'zayavki_ids' => (string)($row['zayavki_ids'] ?? ''),
        'zayavki_count' => isset($row['zayavki_count']) ? (int)$row['zayavki_count'] : 0,
        'driver_id' => isset($row['driver_id']) ? (int)$row['driver_id'] : null,
        'driver_label' => trim((string)($row['driver_label'] ?? '')),
        'planned_start_date_from' => $row['planned_start_date_from'] ?? null,
        'planned_start_date_to' => $row['planned_start_date_to'] ?? null,
        'assigned_manager_id' => isset($row['assigned_manager_id']) ? (int)$row['assigned_manager_id'] : null,
        'total_kg' => isset($row['total_kg']) ? (float)$row['total_kg'] : 0,
    ];
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }

    $managerId = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0;

    $sql = "
        SELECT f.id,
               f.status,
               f.comment AS name,
               f.cost,
               f.zayavki_ids,
               f.zayavki_count,
               f.driver_id,
               f.planned_start_date_from,
               f.planned_start_date_to,
               f.assigned_manager_id,
               CONCAT(COALESCE(d.vehicle_make_plate, ''), CASE WHEN d.full_name IS NOT NULL AND d.full_name <> '' THEN CONCAT(' (', d.full_name, ')') ELSE '' END) AS driver_label,
               COALESCE(fm.total_kg, 0) AS total_kg
        FROM flights f
        LEFT JOIN drivers d ON d.id = f.driver_id
        LEFT JOIN (
            SELECT x.flight_id, SUM(COALESCE(fe.mass_netto, 0) * 1000) AS total_kg
            FROM (
                SELECT f2.id AS flight_id,
                       CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(f2.zayavki_ids, ',', n.n), ',', -1) AS UNSIGNED) AS z_id
                FROM flights f2
                JOIN (
                    SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
                    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
                    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
                    UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
                    UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
                    UNION ALL SELECT 26 UNION ALL SELECT 27 UNION ALL SELECT 28 UNION ALL SELECT 29 UNION ALL SELECT 30
                    UNION ALL SELECT 31 UNION ALL SELECT 32 UNION ALL SELECT 33 UNION ALL SELECT 34 UNION ALL SELECT 35
                    UNION ALL SELECT 36 UNION ALL SELECT 37 UNION ALL SELECT 38 UNION ALL SELECT 39 UNION ALL SELECT 40
                ) n ON n.n <= 1 + LENGTH(COALESCE(f2.zayavki_ids, '')) - LENGTH(REPLACE(COALESCE(f2.zayavki_ids, ''), ',', ''))
            ) x
            LEFT JOIN feo fe ON fe.zayavka_id = x.z_id
            GROUP BY x.flight_id
        ) fm ON fm.flight_id = f.id
        WHERE f.status IN ('planned_route', 'found')
    ";

    $params = [];
    if ($managerId > 0) {
        $sql .= " AND f.assigned_manager_id = :manager_id";
        $params[':manager_id'] = $managerId;
    }

    $sql .= " ORDER BY f.block_date DESC";

    $stmt = $pdo->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare routes query');
    }

    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $plannedRoutes = [];
    $foundRoutes = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $normalized = normalizeRouteRow(is_array($row) ? $row : []);
            if ($normalized['status'] === 'found') {
                $foundRoutes[] = $normalized;
            } else {
                $plannedRoutes[] = $normalized;
            }
        }
    }

    safeJson([
        'success' => true,
        'routes' => $plannedRoutes,
        'found_routes' => $foundRoutes,
        'manager_id' => $managerId > 0 ? $managerId : null,
    ]);
} catch (Throwable $e) {
    mapError('get_planned_routes failed', ['error' => $e->getMessage()]);
    safeJson(['success' => false, 'message' => 'Ошибка загрузки маршрутов']);
}
