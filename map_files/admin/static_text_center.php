<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

$flash = '';
$flashType = 'ok';
if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)maxAdminPost('action') === 'login') {
    if (hash_equals(maxAdminPasswordConst(), (string)maxAdminPost('password'))) {
        $_SESSION['max_admin_auth'] = 1;
        header('Location: static_text_center.php');
        exit;
    }
    $flash = 'Неверный пароль';
    $flashType = 'err';
}
if (maxAdminIsAuthed() && (string)($_GET['logout'] ?? '') === '1') {
    unset($_SESSION['max_admin_auth']);
    session_destroy();
    header('Location: static_text_center.php');
    exit;
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Static Text Center</title>
<style>
body{margin:0;background:#101820;color:#d9e2ec;font-family:Segoe UI,Arial,sans-serif}
.wrap{max-width:1380px;margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px}
.linkbar{display:flex;gap:8px;align-items:center}
.panel{background:#162331;border:1px solid #274056;border-radius:8px;padding:10px;margin-bottom:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
input[type=text],select,textarea{width:100%;box-sizing:border-box;border:1px solid #35536b;background:#0f1a24;color:#e6edf3;border-radius:6px;padding:7px 8px;font-size:13px}
textarea{min-height:90px}
.btn{height:32px;border:1px solid #3f6079;background:#23384a;color:#e6edf3;border-radius:6px;padding:0 10px;cursor:pointer}
.btn.primary{background:#0f7f75;border-color:#0f9d90}
.table{width:100%;border-collapse:collapse}
.table th,.table td{font-size:12px;padding:6px;border-bottom:1px solid #2f4b61;vertical-align:top;text-align:left}
.table th{color:#b8d2e5;background:#172738}
.small{font-size:12px;color:#96acbf}
.login{max-width:420px;margin:80px auto}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="linkbar">
      <h2 style="margin:0">Static Text Center</h2>
      <a class="btn" href="max_event_center.php" style="text-decoration:none;display:inline-flex;align-items:center">MAX Event Center</a>
      <a class="btn" href="max_admin.php" style="text-decoration:none;display:inline-flex;align-items:center">Legacy MAX Admin</a>
    </div>
    <?php if (maxAdminIsAuthed()): ?><a class="btn" href="?logout=1" style="text-decoration:none;display:inline-flex;align-items:center">Выйти</a><?php endif; ?>
  </div>

  <?php if ($flash !== ''): ?><div class="panel" style="border-color:<?= $flashType === 'err' ? '#a94f4f' : '#3e7d67' ?>"><?= maxAdminHtml($flash) ?></div><?php endif; ?>

  <?php if (!maxAdminIsAuthed()): ?>
    <div class="panel login">
      <form method="post">
        <input type="hidden" name="action" value="login">
        <label class="small">Пароль доступа</label>
        <input type="password" name="password" required>
        <div style="height:8px"></div>
        <button class="btn primary" type="submit">Войти</button>
      </form>
    </div>
  <?php else: ?>
  <div class="panel">
    <div class="row" style="justify-content:space-between">
      <div class="row" style="flex:1 1 auto">
        <input id="search" type="text" placeholder="Поиск по KEY, тексту, категории, описанию" style="max-width:420px">
        <select id="category" style="max-width:220px">
          <option value="">Все категории</option>
          <option value="routes">Рейсы</option>
          <option value="drivers">Водители</option>
          <option value="warehouses">Склады</option>
          <option value="slitex">SLITEX</option>
          <option value="max">MAX</option>
          <option value="buttons">Кнопки</option>
          <option value="hints">Подсказки</option>
          <option value="errors">Ошибки</option>
          <option value="system">Системные</option>
        </select>
        <button class="btn" id="btn-search" type="button">Искать</button>
      </div>
      <div class="row">
        <button class="btn" id="btn-seed" type="button">Загрузить ключевые тексты</button>
        <span id="status" class="small"></span>
      </div>
    </div>
  </div>

  <div class="panel">
    <table class="table" id="texts-table">
      <thead><tr><th style="width:17%">KEY</th><th style="width:10%">Категория</th><th style="width:14%">Название</th><th style="width:19%">Описание</th><th style="width:30%">Текст</th><th style="width:10%">Где используется</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (maxAdminIsAuthed()): ?>
<script>
(() => {
  const endpoint = 'static_text_center_actions.php';
  const tableBody = document.querySelector('#texts-table tbody');
  const statusEl = document.getElementById('status');

  const lock = (btn, v, text='') => {
    if (!btn) return;
    if (v) {
      btn.dataset.original = btn.textContent;
      btn.disabled = true;
      btn.textContent = text || 'Выполняется...';
    } else {
      btn.disabled = false;
      if (btn.dataset.original) btn.textContent = btn.dataset.original;
    }
  };

  async function api(action, payload = {}, button = null) {
    lock(button, true);
    const fd = new FormData();
    fd.set('action', action);
    Object.entries(payload).forEach(([k,v]) => fd.set(k, v));
    try {
      const res = await fetch(endpoint, {method: 'POST', body: fd, credentials: 'same-origin'});
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Ошибка');
      return data;
    } finally {
      lock(button, false);
    }
  }

  function setStatus(text, isError = false) {
    statusEl.textContent = text;
    statusEl.style.color = isError ? '#ff9b9b' : '#7fd3b6';
  }

  function escapeHtml(text) {
    return (text || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function renderRows(rows) {
    tableBody.innerHTML = '';
    const filterCat = document.getElementById('category').value;
    rows
      .filter((row) => !filterCat || (row.category || '') === filterCat)
      .forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><input type="text" data-key="text_key" value="${escapeHtml(row.text_key || '')}" readonly></td>
        <td><input type="text" data-key="category" value="${escapeHtml(row.category || '')}"></td>
        <td><input type="text" data-key="title" value="${escapeHtml(row.title || '')}"></td>
        <td><textarea data-key="description" rows="3">${escapeHtml(row.description || '')}</textarea></td>
        <td><textarea data-key="text_value" rows="4">${escapeHtml(row.text_value || '')}</textarea></td>
        <td>
          <textarea data-key="usage_path" rows="3">${escapeHtml(row.usage_path || '')}</textarea>
          <div style="height:6px"></div>
          <button class="btn primary" type="button" data-save="1">Сохранить</button>
        </td>
      `;
      const btn = tr.querySelector('button[data-save]');
      btn.addEventListener('click', async () => {
        const payload = {
          text_key: tr.querySelector('[data-key="text_key"]').value,
          category: tr.querySelector('[data-key="category"]').value,
          title: tr.querySelector('[data-key="title"]').value,
          description: tr.querySelector('[data-key="description"]').value,
          text_value: tr.querySelector('[data-key="text_value"]').value,
          usage_path: tr.querySelector('[data-key="usage_path"]').value,
        };
        try {
          await api('save_text', payload, btn);
          setStatus('Текст сохранен');
        } catch (e) {
          setStatus(e.message || 'Ошибка сохранения', true);
        }
      });
      tableBody.appendChild(tr);
    });
  }

  async function loadRows(button = null) {
    setStatus('Загрузка...');
    try {
      const data = await api('load_texts', {search: document.getElementById('search').value || ''}, button);
      renderRows(data.data.rows || []);
      setStatus('Загружено: ' + ((data.data.rows || []).length));
    } catch (e) {
      setStatus(e.message || 'Ошибка загрузки', true);
    }
  }

  document.getElementById('btn-search').addEventListener('click', (e) => loadRows(e.currentTarget));
  document.getElementById('category').addEventListener('change', () => loadRows());
  document.getElementById('search').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      loadRows();
    }
  });

  document.getElementById('btn-seed').addEventListener('click', async (e) => {
    setStatus('Регистрация текстов...');
    try {
      const data = await api('seed_texts', {}, e.currentTarget);
      setStatus(data.message || 'Готово');
      await loadRows();
    } catch (err) {
      setStatus(err.message || 'Ошибка', true);
    }
  });

  loadRows();
})();
</script>
<?php endif; ?>
</body></html>
