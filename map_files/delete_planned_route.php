<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не разрешен'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) throw new Exception('Неверный ID');

    $dbName = $dbConfig['dbname'] ?? ($dbConfig['database'] ?? null);
    $dbPort = $dbConfig['port'] ?? 3306;
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->prepare("DELETE FROM flights WHERE id = :id AND status = 'planned_route'");
    $stmt->execute([':id' => $id]);
    echo json_encode(['success' => true, 'message' => 'Маршрут удален'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка удаления: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;
