<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Неверный метод'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception('Неверный JSON');

    $name = trim($data['name'] ?? '');
    $zayavkiIds = $data['zayavki_ids'] ?? '';
    $costRaw = $data['cost'] ?? null;
    $cost = ($costRaw !== '' && is_numeric($costRaw) && $costRaw != 0) ? floatval($costRaw) : null;
    $routeId = isset($data['id']) ? intval($data['id']) : 0;

    if (empty($name) || empty($zayavkiIds)) throw new Exception('Название и ID заявок обязательны');

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }
    $count = count(array_filter(explode(',', $zayavkiIds)));

    if ($routeId > 0) {
        $stmt = $pdo->prepare("UPDATE flights SET comment = :comment, cost = :cost, zayavki_ids = :zayavki_ids, zayavki_count = :count, block_date = NOW() WHERE id = :id AND status = 'planned_route'");
        $stmt->execute([
            ':comment' => $name,
            ':cost' => $cost,
            ':zayavki_ids' => $zayavkiIds,
            ':count' => $count,
            ':id' => $routeId
        ]);

        echo json_encode(['success' => true, 'message' => "Маршрут «{$name}» обновлен"], JSON_UNESCAPED_UNICODE);
    } else {
        $stmt = $pdo->prepare("INSERT INTO flights (status, comment, cost, zayavki_ids, zayavki_count, assigned_manager_id, block_date) VALUES ('planned_route', :comment, :cost, :zayavki_ids, :count, NULL, NOW())");
        $stmt->execute([
            ':comment' => $name,
            ':cost' => $cost,
            ':zayavki_ids' => $zayavkiIds,
            ':count' => $count
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => "Маршрут «{$name}» создан"], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;
