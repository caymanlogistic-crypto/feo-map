<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $dbName = $dbConfig['dbname'] ?? ($dbConfig['database'] ?? null);
    $dbPort = $dbConfig['port'] ?? 3306;
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query("SELECT id, comment as name, cost, zayavki_ids, zayavki_count FROM flights WHERE status = 'planned_route' ORDER BY block_date DESC");
    echo json_encode(['success' => true, 'routes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;
