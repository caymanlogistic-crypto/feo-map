<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

maxAdminRequireAuthJson();
if (!isset($pdo) || !($pdo instanceof PDO)) {
    maxAdminJsonOut(['success' => false, 'error' => 'Database connection error']);
}

function stColumns(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM ui_static_texts');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $map = [];
        foreach ((array)$rows as $row) {
            $f = (string)($row['Field'] ?? '');
            if ($f !== '') {
                $map[$f] = true;
            }
        }
        $cache = $map;
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function stReady(PDO $pdo): bool
{
    return !empty(stColumns($pdo));
}

function stSeedCatalog(): array
{
    return [
        ['key' => 'route.modal.title', 'category' => 'routes', 'name' => 'Заголовок модального окна рейса', 'description' => 'Шапка формы редактирования рейса', 'text' => 'Редактирование рейса #{id}', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.status.planned', 'category' => 'routes', 'name' => 'Статус планируемый', 'description' => 'Текст статуса planned_route', 'text' => 'Планируемый маршрут', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.status.found', 'category' => 'routes', 'name' => 'Статус сформирован', 'description' => 'Текст статуса found', 'text' => 'Рейс сформирован', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'route.status.started', 'category' => 'routes', 'name' => 'Статус started', 'description' => 'Текст статуса started', 'text' => 'Вывоз начался', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'driver.new.title', 'category' => 'drivers', 'name' => 'Заголовок нового водителя', 'description' => 'Mini-modal создания водителя', 'text' => 'Новый водитель', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.field.full_name.hint', 'category' => 'hints', 'name' => 'Подсказка по ФИО', 'description' => 'Подсказка под полем ФИО', 'text' => 'Формат: Иванов Иван Иванович', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'driver.field.plate.hint', 'category' => 'hints', 'name' => 'Подсказка по госномеру', 'description' => 'Подсказка под полем госномера', 'text' => 'Формат: А123АА45 или А123АА456', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'warehouse.new.title', 'category' => 'warehouses', 'name' => 'Заголовок нового склада', 'description' => 'Mini-modal создания склада', 'text' => 'Новый склад', 'usage' => 'map_files/assets/maptest.js'],
        ['key' => 'button.save', 'category' => 'buttons', 'name' => 'Кнопка сохранить', 'description' => 'Базовая кнопка', 'text' => 'Сохранить', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'button.cancel', 'category' => 'buttons', 'name' => 'Кнопка отмена', 'description' => 'Базовая кнопка', 'text' => 'Отмена', 'usage' => 'map_files/Views/panels.php'],
        ['key' => 'max.test.success', 'category' => 'max', 'name' => 'Успех теста MAX', 'description' => 'Служебное сообщение статуса', 'text' => 'Тест отправлен', 'usage' => 'map_files/admin/max_event_center.php'],
    ];
}

function stSaveRow(PDO $pdo, array $row): void
{
    $cols = stColumns($pdo);
    $set = [];
    $params = [];
    $put = static function(string $column, $value) use (&$set, &$params, $cols): void {
        if (!isset($cols[$column])) return;
        $set[] = "`{$column}` = :{$column}";
        $params[":{$column}"] = $value;
    };
    $put('text_key', (string)($row['key'] ?? ''));
    $put('key_name', (string)($row['key'] ?? ''));
    $put('category', (string)($row['category'] ?? 'system'));
    $put('title', (string)($row['name'] ?? ''));
    $put('name', (string)($row['name'] ?? ''));
    $put('description', (string)($row['description'] ?? ''));
    $put('text_value', (string)($row['text'] ?? ''));
    $put('text', (string)($row['text'] ?? ''));
    $put('usage_path', (string)($row['usage'] ?? ''));
    $put('used_in', (string)($row['usage'] ?? ''));

    if (empty($set)) {
        return;
    }

    $keyColumn = isset($cols['text_key']) ? 'text_key' : (isset($cols['key_name']) ? 'key_name' : '');
    if ($keyColumn === '') {
        throw new RuntimeException('Не найден ключевой столбец ui_static_texts');
    }

    $sql = 'INSERT INTO ui_static_texts SET ' . implode(', ', $set) . ' ON DUPLICATE KEY UPDATE ';
    $updates = [];
    foreach ($set as $assign) {
        [$left] = explode('=', $assign, 2);
        $left = trim($left);
        if ($left === "`{$keyColumn}`") continue;
        $updates[] = $left . ' = VALUES(' . $left . ')';
    }
    if (empty($updates)) {
        $updates[] = "`{$keyColumn}` = VALUES(`{$keyColumn}`)";
    }
    $sql .= implode(', ', $updates);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function stLoadRows(PDO $pdo, string $q = ''): array
{
    if (!stReady($pdo)) {
        return [];
    }
    $cols = stColumns($pdo);
    $keyCol = isset($cols['text_key']) ? 'text_key' : (isset($cols['key_name']) ? 'key_name' : '');
    $textCol = isset($cols['text_value']) ? 'text_value' : (isset($cols['text']) ? 'text' : '');
    $nameCol = isset($cols['title']) ? 'title' : (isset($cols['name']) ? 'name' : '');
    $usageCol = isset($cols['usage_path']) ? 'usage_path' : (isset($cols['used_in']) ? 'used_in' : '');
    if ($keyCol === '' || $textCol === '') {
        return [];
    }

    $where = '';
    $params = [];
    if ($q !== '') {
        $where = "WHERE (`{$keyCol}` LIKE :q OR `{$textCol}` LIKE :q";
        if (isset($cols['category'])) $where .= " OR `category` LIKE :q";
        if ($nameCol !== '') $where .= " OR `{$nameCol}` LIKE :q";
        if (isset($cols['description'])) $where .= " OR `description` LIKE :q";
        $where .= ')';
        $params[':q'] = '%' . $q . '%';
    }

    $select = ["`{$keyCol}` AS text_key", "`{$textCol}` AS text_value"];
    $select[] = isset($cols['category']) ? '`category` AS category' : "'' AS category";
    $select[] = $nameCol !== '' ? "`{$nameCol}` AS title" : "'' AS title";
    $select[] = isset($cols['description']) ? '`description` AS description' : "'' AS description";
    $select[] = $usageCol !== '' ? "`{$usageCol}` AS usage_path" : "'' AS usage_path";
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ui_static_texts ' . $where . ' ORDER BY text_key ASC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$action = trim((string)maxAdminPost('action'));
if ($action === '') {
    maxAdminJsonOut(['success' => false, 'error' => 'Пустое действие']);
}
if (!stReady($pdo)) {
    maxAdminJsonOut(['success' => false, 'error' => 'Таблица ui_static_texts не найдена']);
}

try {
    if ($action === 'seed_texts') {
        $catalog = stSeedCatalog();
        foreach ($catalog as $row) {
            stSaveRow($pdo, $row);
        }
        maxAdminJsonOut(['success' => true, 'message' => 'Ключевые тексты зарегистрированы']);
    }

    if ($action === 'load_texts') {
        $q = trim((string)maxAdminPost('search', ''));
        maxAdminJsonOut(['success' => true, 'data' => ['rows' => stLoadRows($pdo, $q)]]);
    }

    if ($action === 'save_text') {
        $key = trim((string)maxAdminPost('text_key', ''));
        if ($key === '') {
            throw new RuntimeException('Пустой KEY');
        }
        stSaveRow($pdo, [
            'key' => $key,
            'category' => trim((string)maxAdminPost('category', 'system')),
            'name' => trim((string)maxAdminPost('title', '')),
            'description' => trim((string)maxAdminPost('description', '')),
            'text' => (string)maxAdminPost('text_value', ''),
            'usage' => trim((string)maxAdminPost('usage_path', '')),
        ]);
        maxAdminJsonOut(['success' => true, 'message' => 'Текст сохранен']);
    }

    maxAdminJsonOut(['success' => false, 'error' => 'Неизвестное действие']);
} catch (Throwable $e) {
    maxAdminJsonOut(['success' => false, 'error' => $e->getMessage()]);
}
