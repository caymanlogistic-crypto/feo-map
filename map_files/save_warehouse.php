<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function outWarehouse(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function readWarehouseInput(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $input = readWarehouseInput();
    $name = trim((string)($input['name'] ?? ''));
    $fullAddress = trim((string)($input['full_address'] ?? $input['address'] ?? ''));
    $latRaw = $input['latitude'] ?? null;
    $lonRaw = $input['longitude'] ?? null;

    if ($name === '' || $fullAddress === '') {
        outWarehouse([
            'success' => false,
            'message' => 'Заполните обязательные поля: название и полный адрес склада.'
        ]);
    }

    $latitude = null;
    if ($latRaw !== null && $latRaw !== '') {
        if (!is_numeric($latRaw)) {
            outWarehouse(['success' => false, 'message' => 'Некорректная широта.']);
        }
        $latitude = (float)$latRaw;
    }

    $longitude = null;
    if ($lonRaw !== null && $lonRaw !== '') {
        if (!is_numeric($lonRaw)) {
            outWarehouse(['success' => false, 'message' => 'Некорректная долгота.']);
        }
        $longitude = (float)$lonRaw;
    }

    $stmtCols = $pdo->query('SHOW COLUMNS FROM warehouses');
    $columns = $stmtCols ? $stmtCols->fetchAll(PDO::FETCH_COLUMN) : [];
    $columnMap = [];
    foreach ((array)$columns as $column) {
        $columnMap[(string)$column] = true;
    }

    $nameColumn = isset($columnMap['name']) ? 'name' : (isset($columnMap['title']) ? 'title' : null);
    $addressColumn = isset($columnMap['full_address']) ? 'full_address' : (isset($columnMap['address']) ? 'address' : null);
    if ($nameColumn === null || $addressColumn === null || !isset($columnMap['id'])) {
        outWarehouse(['success' => false, 'message' => 'Структура таблицы складов не поддерживается.']);
    }

    $fields = [$nameColumn, $addressColumn];
    $params = [':name' => $name, ':address' => $fullAddress];
    $placeholders = [':name', ':address'];

    if (isset($columnMap['latitude'])) {
        $fields[] = 'latitude';
        $placeholders[] = ':latitude';
        $params[':latitude'] = $latitude;
    }
    if (isset($columnMap['longitude'])) {
        $fields[] = 'longitude';
        $placeholders[] = ':longitude';
        $params[':longitude'] = $longitude;
    }
    if (isset($columnMap['is_active'])) {
        $fields[] = 'is_active';
        $placeholders[] = ':is_active';
        $params[':is_active'] = 1;
    }

    $quotedFields = array_map(static fn($f) => '`' . str_replace('`', '``', $f) . '`', $fields);
    $sql = 'INSERT INTO warehouses (' . implode(', ', $quotedFields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $id = (int)$pdo->lastInsertId();

    if ($id <= 0) {
        outWarehouse(['success' => false, 'message' => 'Не удалось сохранить склад.']);
    }

    outWarehouse([
        'success' => true,
        'warehouse' => [
            'id' => $id,
            'name' => $name,
            'full_address' => $fullAddress,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]
    ]);
} catch (Throwable $e) {
    if (function_exists('mapError')) {
        mapError('save_warehouse failed', ['error' => $e->getMessage()]);
    }
    outWarehouse([
        'success' => false,
        'message' => 'Ошибка сохранения склада.'
    ]);
}

