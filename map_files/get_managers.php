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

function pickManagerDisplayName(array $row): string
{
    $candidates = [];
    foreach (['full_name', 'name'] as $field) {
        if (isset($row[$field])) {
            $value = trim((string)$row[$field]);
            if ($value !== '') {
                $candidates[] = $value;
            }
        }
    }

    if (empty($candidates)) {
        $first = '';
        $last = '';
        foreach (['first_name', 'firstname', 'given_name'] as $field) {
            if (isset($row[$field]) && trim((string)$row[$field]) !== '') {
                $first = trim((string)$row[$field]);
                break;
            }
        }
        foreach (['last_name', 'lastname', 'surname', 'family_name'] as $field) {
            if (isset($row[$field]) && trim((string)$row[$field]) !== '') {
                $last = trim((string)$row[$field]);
                break;
            }
        }
        $combined = trim($last . ' ' . $first);
        if ($combined !== '') {
            $candidates[] = $combined;
        }
    }

    if (empty($candidates)) {
        foreach (['username', 'login'] as $field) {
            if (isset($row[$field])) {
                $value = trim((string)$row[$field]);
                if ($value !== '') {
                    $candidates[] = $value;
                    break;
                }
            }
        }
    }

    if (!empty($candidates)) {
        return $candidates[0];
    }

    return 'Менеджер #' . (int)($row['id'] ?? 0);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not initialized');
    }

    $columnsStmt = $pdo->query('SHOW COLUMNS FROM users');
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $columnsMap = [];
    foreach ((array)$columns as $col) {
        $columnsMap[(string)$col] = true;
    }

    if (!isset($columnsMap['id'])) {
        throw new RuntimeException('Users table does not contain id column');
    }

    $roleColumn = null;
    foreach (['role', 'user_role', 'type'] as $candidate) {
        if (isset($columnsMap[$candidate])) {
            $roleColumn = $candidate;
            break;
        }
    }

    $selectColumns = ['id'];
    foreach (['full_name', 'name', 'first_name', 'firstname', 'given_name', 'last_name', 'lastname', 'surname', 'family_name', 'username', 'login'] as $field) {
        if (isset($columnsMap[$field])) {
            $selectColumns[] = $field;
        }
    }
    if ($roleColumn !== null) {
        $selectColumns[] = $roleColumn;
    }

    $sql = 'SELECT ' . implode(', ', array_unique($selectColumns)) . ' FROM users';
    if ($roleColumn !== null) {
        $sql .= " WHERE LOWER(TRIM($roleColumn)) = 'logist'";
    }

    $stmt = $pdo->query($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to query managers');
    }

    $managers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }

        if ($roleColumn === null) {
            mapError('get_managers: role column not found in users table');
            continue;
        }

        $id = isset($row['id']) ? (int)$row['id'] : 0;
        if ($id <= 0) {
            continue;
        }

        $managers[] = [
            'id' => $id,
            'name' => pickManagerDisplayName($row),
        ];
    }

    usort($managers, static function (array $a, array $b): int {
        return strcmp((string)$a['name'], (string)$b['name']);
    });

    outJson(['success' => true, 'managers' => $managers]);
} catch (Throwable $e) {
    mapError('get_managers failed', ['error' => $e->getMessage()]);
    outJson(['success' => false, 'message' => 'Не удалось загрузить менеджеров']);
}
