<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); echo 'Database connection error'; exit; }

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)maxAdminPost('action') === 'login') {
    if (hash_equals(maxAdminPasswordConst(), (string)maxAdminPost('password'))) { $_SESSION['max_admin_auth'] = 1; header('Location: slitex_tracker_rename.php'); exit; }
    $flash = 'Неверный пароль'; $flashType = 'err';
}
if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') { unset($_SESSION['max_admin_auth']); session_destroy(); header('Location: slitex_tracker_rename.php'); exit; }

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── SLITEX helpers ───────────────────────────────────────────────────────────
function stGetSlitexConfig(): array {
    $baseUrl = trim((string)($GLOBALS['slitexBaseUrl'] ?? getenv('SLITEX_BASE_URL') ?: 'https://slitex.online'));
    $token = trim((string)($GLOBALS['slitexApiToken'] ?? getenv('SLITEX_API_TOKEN') ?: ''));
    return ['base_url' => rtrim($baseUrl, '/'), 'token' => $token];
}

function stSlitexRequest(string $method, string $url, string $token, ?array $payload = null): array {
    $ch = curl_init($url);
    if ($ch === false) return ['success' => false, 'status' => 0, 'error' => 'curl_init failed', 'body' => ''];
    $headers = ['Accept: application/json', 'X-API-Token: ' . $token];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method)];
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE); }
    $options[CURLOPT_HTTPHEADER] = $headers; curl_setopt_array($ch, $options);
    $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = (string)curl_error($ch); curl_close($ch);
    return ['success' => $status >= 200 && $status < 300, 'status' => $status, 'error' => $err, 'body' => (string)$resp];
}

// ── State ────────────────────────────────────────────────────────────────────
$flash = ''; $flashType = 'ok'; $result = null;
$searchUid = trim((string)($_GET['search_uid'] ?? '')); $searchName = trim((string)($_GET['search_name'] ?? ''));
$cfg = stGetSlitexConfig();

// ── Fetch devices ────────────────────────────────────────────────────────────
$devices = [];
$devTotal = 0;
if (maxAdminIsAuthed() && $cfg['token'] !== '') {
    $dr = stSlitexRequest('GET', $cfg['base_url'] . '/api/external/devices', $cfg['token']);
    if ($dr['success']) {
        $dd = json_decode($dr['body'], true);
        $all = is_array($dd) ? ($dd['data'] ?? $dd) : [];
        $devTotal = count($all);
        foreach ($all as $d) {
            if (!is_array($d) || empty($d['uniqueid'])) continue;
            $uid = trim((string)$d['uniqueid']);
            $nm = trim((string)($d['name'] ?? ''));
            if ($searchUid !== '' && stripos($uid, $searchUid) === false) continue;
            if ($searchName !== '' && stripos($nm, $searchName) === false) continue;
            $devices[] = ['uniqueid' => $uid, 'name' => $nm, 'status' => $d['status'] ?? '—', 'lastupdate' => $d['lastupdate'] ?? '—', 'created' => $d['created'] ?? '—'];
        }
    } else {
        $flash = 'Не удалось получить список устройств SLITEX: HTTP ' . $dr['status']; $flashType = 'err';
    }
}

// ── POST: rename ─────────────────────────────────────────────────────────────
if (maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && maxAdminPost('action') === 'rename') {
    $renameUid = trim((string)maxAdminPost('uniqueid', ''));
    $renameName = trim((string)maxAdminPost('name', ''));
    if ($renameUid === '') { $flash = 'Введите uniqueid трекера'; $flashType = 'err'; }
    elseif ($renameName === '') { $flash = 'Введите новое имя'; $flashType = 'err'; }
    elseif (mb_strlen($renameUid) < 3 || mb_strlen($renameUid) > 32) { $flash = 'uniqueid должен быть 3–32 символа'; $flashType = 'err'; }
    elseif (mb_strlen($renameName) > 100) { $flash = 'Имя не должно превышать 100 символов'; $flashType = 'err'; }
    elseif ($renameName !== strip_tags($renameName)) { $flash = 'Имя не должно содержать HTML-теги'; $flashType = 'err'; }
    else {
        $rr = stSlitexRequest('PATCH', $cfg['base_url'] . '/api/external/devices/' . rawurlencode($renameUid) . '/name', $cfg['token'], ['name' => $renameName]);
        $rd = json_decode($rr['body'], true);
        $result = [
            'success' => $rr['success'],
            'http_status' => $rr['status'],
            'uniqueid' => $renameUid,
            'new_name' => $renameName,
            'slitex_response' => is_array($rd) ? json_encode($rd, JSON_UNESCAPED_UNICODE) : mb_substr($rr['body'], 0, 500),
        ];
        if ($rr['success']) {
            $flash = "SLITEX обновлён: {$renameUid} → {$renameName}"; $flashType = 'ok';
        } else {
            $flash = "SLITEX ошибка HTTP {$rr['status']}: " . mb_substr($rr['body'], 0, 200); $flashType = 'err';
        }
    }
}

?><!doctype html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SLITEX — переименование трекера</title>
<style>
body{margin:0;background:#f4f6f8;color:#1e293b;font-family:Arial,sans-serif}
.wrap{max-width:calc(100vw - 48px);margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.admin-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.admin-nav .btn{padding:5px 10px;font-size:12px}
.admin-nav .admin-nav-active{background:#0ea5b7;color:#fff;border-color:#0b7285;font-weight:600}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:14px;margin-bottom:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}
input[type=text]{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;background:#fff;color:#1e293b;width:220px}
.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center;text-decoration:none;color:#1e293b}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}
.btn.success{background:#10b981;color:#fff;border-color:#059669}
.small{font-size:12px;color:#64748b}
.ok{background:#ecfdf3;border-color:#b7e4c7;color:#0f766e}
.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
.warn-bg{background:#fffbeb;border-color:#fde68a;color:#92400e}
.hint{font-size:12px;color:#334155;background:#f8fafc;border-left:3px solid #0ea5b7;padding:8px 10px;border-radius:4px;margin:6px 0}
.login{max-width:420px;margin:80px auto}
table{width:100%;border-collapse:collapse}
th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f8fafc;color:#475569;font-weight:600}
tr:hover{background:#f8fafc}
.mono{font-family:Consolas,monospace;font-size:12px}
</style></head><body><div class="wrap">
<div class="top">
  <h2 style="margin:0;font-size:18px">SLITEX — переименование трекера</h2>
  <?php renderAdminNav('slitex_tracker_rename'); ?>
</div>

<?php if ($flash !== ''): ?><div class="card <?=$flashType==='err'?'err':($flashType==='warn'?'warn-bg':'ok')?>"><?=h($flash)?></div><?php endif; ?>

<?php if (!maxAdminIsAuthed()): ?>
<div class="card login"><form method="post"><input type="hidden" name="action" value="login"><label>Пароль доступа</label><input type="password" name="password" required style="width:100%"><div style="height:8px"></div><button class="btn primary" type="submit">Войти</button></form></div>
<?php else: ?>

<?php if ($cfg['token'] === ''): ?>
<div class="card err">SLITEX API token не настроен. Проверьте конфигурацию сервера.</div>
<?php else: ?>

<div class="hint">Эта страница меняет NAME трекера в SLITEX. Используйте для возврата тестовых трекеров в свободный вид: NAME = uniqueid. <br>Пример: если трекер <b>654903</b> переименован в <b>Т999ТТ199(Тестовый)</b>, укажите uniqueid <b>654903</b> и name <b>654903</b>.</div>

<div class="card">
  <form method="post">
    <input type="hidden" name="action" value="rename">
    <div class="row" style="margin-bottom:8px">
      <label><strong>uniqueid</strong> <input type="text" name="uniqueid" value="<?=h(maxAdminPost('uniqueid',''))?>" placeholder="654903" required style="width:200px"></label>
      <label><strong>Новое имя</strong> <input type="text" name="name" value="<?=h(maxAdminPost('name',''))?>" placeholder="654903 или Т999ТТ199(Тестовый)" required style="width:280px"></label>
    </div>
    <div class="row">
      <button class="btn primary" type="submit">Переименовать в SLITEX</button>
      <button class="btn success" type="button" onclick="document.querySelector('[name=name]').value=document.querySelector('[name=uniqueid]').value; this.form.submit();">Вернуть NAME = uniqueid</button>
    </div>
  </form>
</div>

<?php if (is_array($result)): ?>
<div class="card <?=$result['success']?'ok':'err'?>">
  <strong>Результат:</strong><br>
  HTTP: <?=$result['http_status']?><br>
  uniqueid: <?=h($result['uniqueid'])?> → <?=h($result['new_name'])?><br>
  <details><summary>SLITEX response</summary><pre class="mono" style="font-size:11px;white-space:pre-wrap"><?=h($result['slitex_response'])?></pre></details>
</div>
<?php endif; ?>

<!-- Device list -->
<div class="card">
  <div class="row" style="justify-content:space-between;margin-bottom:8px"><strong>Устройства SLITEX</strong><span class="small">Всего: <?=$devTotal?>, показано: <?=count($devices)?></span></div>
  <form method="get" style="margin-bottom:8px">
    <div class="row">
      <input type="text" name="search_uid" value="<?=h($searchUid)?>" placeholder="Поиск по uniqueid" style="width:180px">
      <input type="text" name="search_name" value="<?=h($searchName)?>" placeholder="Поиск по name" style="width:180px">
      <button class="btn primary" type="submit">Фильтр</button>
      <?php if ($searchUid !== '' || $searchName !== ''): ?><a class="btn" href="slitex_tracker_rename.php">Сброс</a><?php endif; ?>
    </div>
  </form>
  <?php if (empty($devices)): ?><p class="small">Устройства не найдены.</p>
  <?php else: ?>
  <div style="max-height:500px;overflow:auto">
  <table>
    <thead><tr><th>uniqueid</th><th>name</th><th>status</th><th>created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $dev): ?>
    <tr>
      <td class="mono"><?=h($dev['uniqueid'])?></td>
      <td><?=h($dev['name'])?></td>
      <td class="small"><?=h((string)($dev['status']??'—'))?></td>
      <td class="small"><?=h($dev['created'] ? date('d.m.Y', strtotime($dev['created'])) : '—')?></td>
      <td><a class="btn" style="height:24px;font-size:11px;padding:2px 6px" href="?search_uid=<?=rawurlencode($dev['uniqueid'])?>#rename-form">Выбрать</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php endif; // token check ?>
<?php endif; // authed ?>
</div></body></html>
