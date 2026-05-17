<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }
    $stmt = $pdo->query("SELECT id, comment as name, cost, zayavki_ids, zayavki_count FROM flights WHERE status = 'planned_route' ORDER BY block_date DESC");
    echo json_encode(['success' => true, 'routes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка загрузки: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;
