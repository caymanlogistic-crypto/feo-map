<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function outJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection is not initialized');
    }

    $columnsStmt = $pdo->query('SHOW COLUMNS FROM users');
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $columnsMap = [];
    if (is_array($columns)) {
        foreach ($columns as $col) {
            $columnsMap[(string)$col] = true;
        }
    }

    if (!isset($columnsMap['id'])) {
        throw new Exception('Users table does not contain id column');
    }

    $nameCandidates = ['full_name', 'name', 'username', 'login'];
    $nameSqlParts = [];
    foreach ($nameCandidates as $col) {
        if (isset($columnsMap[$col])) {
            $nameSqlParts[] = "NULLIF(TRIM({$col}), '')";
        }
    }

    $nameExpr = !empty($nameSqlParts)
        ? 'COALESCE(' . implode(', ', $nameSqlParts) . ', CONCAT("Менеджер #", id))'
        : 'CONCAT("Менеджер #", id)';

    $sql = "SELECT id, {$nameExpr} AS manager_name FROM users ORDER BY manager_name ASC";
    $stmt = $pdo->query($sql);
    if (!$stmt) {
        throw new Exception('Failed to query managers');
    }

    $managers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) continue;
        $managers[] = [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'name' => trim((string)($row['manager_name'] ?? '')),
        ];
    }

    outJson(['success' => true, 'managers' => $managers]);
} catch (Throwable $e) {
    mapError('get_managers failed', ['error' => $e->getMessage()]);
    outJson(['success' => false, 'message' => 'Не удалось загрузить менеджеров']);
}
