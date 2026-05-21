<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

const MAX_ADMIN_PASSWORD = '75500';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function isAuthed(): bool { return !empty($_SESSION['max_admin_auth']); }
function requireAuth(): void { if (!isAuthed()) { header('Location: max_admin.php'); exit; } }
function post($k, $d='') { return $_POST[$k] ?? $d; }

function adminTablesReady(PDO $pdo): bool {
    try {
        foreach (['max_settings','max_groups','max_message_templates','max_send_log'] as $t) {
            $s = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $t) . "'");
            if (!$s || !$s->fetchColumn()) return false;
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getSettings(PDO $pdo): array {
    $res = [];
    $stmt = $pdo->query('SELECT setting_key, setting_value FROM max_settings');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ((array)$rows as $r) $res[(string)$r['setting_key']] = (string)$r['setting_value'];
    return $res;
}

function upsertSetting(PDO $pdo, string $k, string $v): void {
    $stmt = $pdo->prepare('INSERT INTO max_settings(setting_key, setting_value) VALUES(:k,:v) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if ($stmt) $stmt->execute([':k'=>$k, ':v'=>$v]);
}

$flash = '';
$flashType = 'ok';

if (!isAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && post('action') === 'login') {
    if (hash_equals(MAX_ADMIN_PASSWORD, (string)post('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: max_admin.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}

if (isAuthed() && ($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: max_admin.php');
    exit;
}

if (isAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)post('action');
    try {
        if ($action === 'save_global') {
            upsertSetting($pdo, 'max_enabled', post('max_enabled','0') === '1' ? '1' : '0');
            upsertSetting($pdo, 'default_group_id', (string)((int)post('default_group_id', '0')));
            $flash = 'Глобальные настройки сохранены';
        } elseif ($action === 'add_group') {
            $title = trim((string)post('title'));
            $groupId = trim((string)post('group_id'));
            if ($title === '' || $groupId === '') {
                throw new RuntimeException('Укажите название и group_id');
            }
            $stmt = $pdo->prepare('INSERT INTO max_groups(title, group_id, is_active, is_default) VALUES(:t,:g,:a,0)');
            $stmt->execute([':t'=>$title, ':g'=>$groupId, ':a'=>post('is_active','0') === '1' ? 1 : 0]);
            $flash = 'Группа добавлена';
        } elseif ($action === 'save_group') {
            $id = (int)post('id');
            $stmt = $pdo->prepare('UPDATE max_groups SET title=:t, group_id=:g, is_active=:a WHERE id=:id');
            $stmt->execute([':t'=>trim((string)post('title')), ':g'=>trim((string)post('group_id')), ':a'=>post('is_active','0') === '1' ? 1 : 0, ':id'=>$id]);
            $flash = 'Группа обновлена';
        } elseif ($action === 'delete_group') {
            $id = (int)post('id');
            $stmt = $pdo->prepare('DELETE FROM max_groups WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            $flash = 'Группа удалена';
        } elseif ($action === 'set_default_group') {
            $id = (int)post('id');
            $pdo->exec('UPDATE max_groups SET is_default=0');
            $stmt = $pdo->prepare('UPDATE max_groups SET is_default=1 WHERE id=:id');
            $stmt->execute([':id'=>$id]);
            upsertSetting($pdo, 'default_group_id', (string)$id);
            $flash = 'Группа по умолчанию изменена';
        } elseif ($action === 'save_template') {
            $id = (int)post('id');
            $stmt = $pdo->prepare('UPDATE max_message_templates SET title=:title, template_text=:template_text, is_enabled=:is_enabled, description=:description WHERE id=:id');
            $stmt->execute([
                ':title'=>trim((string)post('title')),
                ':template_text'=>(string)post('template_text'),
                ':is_enabled'=>post('is_enabled','0') === '1' ? 1 : 0,
                ':description'=>trim((string)post('description')),
                ':id'=>$id,
            ]);
            $flash = 'Шаблон обновлен';
        } elseif ($action === 'seed_templates') {
            $seed = [
                ['planned_to_found','Планируемый → Рейс сформирован'],
                ['found_to_started','Рейс сформирован → Вывоз начался'],
                ['started_to_found_rollback','Вывоз начался → Рейс сформирован'],
                ['found_to_planned_rollback','Рейс сформирован → Планируемый'],
                ['planned_date_added','Добавлена плановая дата'],
                ['planned_date_changed','Изменены плановые даты'],
                ['route_diff_found','Изменение в сформированном рейсе'],
                ['route_diff_started','Изменение в выполняемом рейсе'],
                ['route_deleted','Удаление маршрута'],
                ['route_completed','Груз сдан'],
                ['route_control_cron','Cron-контроль рейсов'],
                ['test_message','Тестовое сообщение'],
            ];
            $stmt = $pdo->prepare('INSERT IGNORE INTO max_message_templates(event_key,title,template_text,is_enabled,description) VALUES(:event_key,:title,:template_text,1,:description)');
            foreach ($seed as $s) {
                $stmt->execute([':event_key'=>$s[0], ':title'=>$s[1], ':template_text'=>'{message}', ':description'=>'Override шаблон для события']);
            }
            $flash = 'Базовые шаблоны добавлены';
        } elseif ($action === 'send_test') {
            $eventKey = trim((string)post('event_key'));
            $message = trim((string)post('message'));
            if ($message === '') {
                throw new RuntimeException('Укажите текст тестового сообщения');
            }
            $res = sendMaxNotify($message, 'markdown', ['event_key' => $eventKey, 'context' => ['message' => $message]]);
            if (empty($res['success'])) {
                throw new RuntimeException('Ошибка отправки: ' . (string)($res['error'] ?? 'unknown'));
            }
            $flash = 'Тестовое сообщение отправлено';
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'err';
    }
}

$ready = adminTablesReady($pdo);
$settings = $ready ? getSettings($pdo) : [];
$groups = [];
$templates = [];
$logs = [];
if ($ready) {
    $g = $pdo->query('SELECT * FROM max_groups ORDER BY is_default DESC, id DESC');
    $groups = $g ? $g->fetchAll(PDO::FETCH_ASSOC) : [];
    $t = $pdo->query('SELECT * FROM max_message_templates ORDER BY event_key ASC');
    $templates = $t ? $t->fetchAll(PDO::FETCH_ASSOC) : [];
    $l = $pdo->query('SELECT * FROM max_send_log ORDER BY id DESC LIMIT 50');
    $logs = $l ? $l->fetchAll(PDO::FETCH_ASSOC) : [];
}
?><!doctype html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Администрирование MAX</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;color:#1e293b;margin:0;padding:16px}
.wrap{max-width:1280px;margin:0 auto}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:12px;margin-bottom:10px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}input[type=text],input[type=password],select,textarea{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;width:100%;box-sizing:border-box}
textarea{min-height:88px}.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:13px}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}.btn.danger{background:#fdf2f2;border-color:#f1b3b3;color:#b42318}
.small{font-size:12px;color:#64748b}.ok{background:#ecfdf3;border-color:#b7e4c7}.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
table{width:100%;border-collapse:collapse}th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px;text-align:left;vertical-align:top}
th{background:#f8fafc}.mono{font-family:Consolas,monospace;white-space:pre-wrap}
@media (max-width:1000px){.grid{grid-template-columns:1fr}}
</style></head><body><div class="wrap">
<div class="top"><h2 style="margin:0">Администрирование MAX</h2><?php if (isAuthed()): ?><a class="btn" href="?logout=1">Выйти</a><?php endif; ?></div>

<?php if ($flash !== ''): ?><div class="card <?= $flashType === 'err' ? 'err' : 'ok' ?>"><?= h($flash) ?></div><?php endif; ?>

<?php if (!isAuthed()): ?>
<div class="card" style="max-width:420px"><form method="post"><input type="hidden" name="action" value="login"><label>Пароль доступа</label><input type="password" name="password" required><div style="height:8px"></div><button class="btn primary" type="submit">Войти</button></form></div>
<?php else: ?>
<?php if (!$ready): ?><div class="card err">Таблицы MAX Admin не найдены. Выполните SQL: <span class="mono">map_files/admin/sql/max_admin_tables.sql</span></div><?php endif; ?>

<div class="grid">
<div class="card"><h3 style="margin:0 0 8px">Глобальные настройки</h3>
<form method="post"><input type="hidden" name="action" value="save_global">
<div class="row"><label><input type="checkbox" name="max_enabled" value="1" <?= (($settings['max_enabled'] ?? '1') === '1') ? 'checked' : '' ?>> Отправка MAX включена</label></div>
<div class="row"><label>Группа по умолчанию</label><select name="default_group_id"><option value="0">Legacy fallback</option><?php foreach($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= ((int)($settings['default_group_id'] ?? 0) === (int)$g['id']) ? 'selected' : '' ?>><?= h($g['title']) ?> (<?= h($g['group_id']) ?>)</option><?php endforeach; ?></select></div>
<button class="btn primary" type="submit">Сохранить</button></form></div>

<div class="card"><h3 style="margin:0 0 8px">Тестовая отправка</h3>
<form method="post"><input type="hidden" name="action" value="send_test"><label>event_key</label><input type="text" name="event_key" value="test_message"><div style="height:6px"></div><label>Текст</label><textarea name="message">Тест MAX уведомления
> 💡 *Проверка markdown-цитаты.*</textarea><div style="height:8px"></div><button class="btn primary" type="submit">Отправить тест</button></form></div>
</div>

<div class="card"><h3 style="margin:0 0 8px">Группы</h3>
<table><thead><tr><th>Название</th><th>group_id</th><th>Активна</th><th>Default</th><th>Действия</th></tr></thead><tbody>
<?php foreach($groups as $g): ?><tr><td><form method="post" class="row"><input type="hidden" name="action" value="save_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="text" name="title" value="<?= h($g['title']) ?>"></td><td><input type="text" name="group_id" value="<?= h($g['group_id']) ?>"></td><td><label><input type="checkbox" name="is_active" value="1" <?= (int)$g['is_active']===1?'checked':'' ?>></label></td><td><?= (int)$g['is_default']===1?'Да':'Нет' ?></td><td><button class="btn" type="submit">Сохранить</button></form><form method="post" style="display:inline"><input type="hidden" name="action" value="set_default_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn" type="submit">Сделать default</button></form><form method="post" style="display:inline" onsubmit="return confirm('Удалить группу?')"><input type="hidden" name="action" value="delete_group"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><button class="btn danger" type="submit">Удалить</button></form></td></tr><?php endforeach; ?></tbody></table>
<div style="height:8px"></div>
<form method="post" class="row"><input type="hidden" name="action" value="add_group"><input type="text" name="title" placeholder="Название группы" style="max-width:280px"><input type="text" name="group_id" placeholder="group_id/chat_id" style="max-width:320px"><label><input type="checkbox" name="is_active" value="1" checked> Активна</label><button class="btn primary" type="submit">Добавить группу</button></form>
</div>

<div class="card"><div class="row" style="justify-content:space-between"><h3 style="margin:0">Шаблоны событий</h3><form method="post"><input type="hidden" name="action" value="seed_templates"><button class="btn" type="submit">Заполнить базовые события</button></form></div>
<?php foreach($templates as $t): ?><form method="post" class="card" style="margin:8px 0;padding:10px;background:#f8fafc"><input type="hidden" name="action" value="save_template"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><div class="row"><strong class="mono" style="min-width:220px"><?= h($t['event_key']) ?></strong><input type="text" name="title" value="<?= h($t['title']) ?>" style="max-width:320px"><label><input type="checkbox" name="is_enabled" value="1" <?= (int)$t['is_enabled']===1?'checked':'' ?>> Включено</label></div><div style="height:6px"></div><textarea name="template_text"><?= h($t['template_text']) ?></textarea><div class="small">Плейсхолдеры: {route_id}, {route_title}, {driver}, {date}, {requests_count}, {weight}, {status_from}, {status_to}, {message}</div><div style="height:6px"></div><input type="text" name="description" value="<?= h($t['description']) ?>"><div style="height:6px"></div><button class="btn" type="submit">Сохранить шаблон</button></form><?php endforeach; ?>
</div>

<div class="card"><h3 style="margin:0 0 8px">Лог отправок (последние 50)</h3>
<table><thead><tr><th>Дата</th><th>Событие</th><th>Группа</th><th>Успех</th><th>Ошибка/ответ</th></tr></thead><tbody>
<?php foreach($logs as $log): ?><tr><td><?= h($log['created_at'] ?? '') ?></td><td class="mono"><?= h($log['event_key'] ?? '') ?></td><td class="mono"><?= h($log['group_id'] ?? '') ?></td><td><?= (int)($log['success'] ?? 0)===1?'OK':'ERR' ?></td><td class="mono"><?= h(trim((string)($log['error_text'] ?? '')) !== '' ? $log['error_text'] : mb_substr((string)($log['response_text'] ?? ''),0,220)) ?></td></tr><?php endforeach; ?></tbody></table>
</div>
<?php endif; ?>
</div></body></html>
