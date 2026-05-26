<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function qAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function qOne($pdo,$sql,$params=[]){$r=qAll($pdo,$sql,$params);return !empty($r)?$r[0]:null;}

echo "=== MAX DIAGNOSTIC ===\n\n";

// Check Event Center tables
echo "--- Event Center Tables ---\n";
foreach (['max_event_templates','max_groups','max_logs','max_pending_queue','system_runtime_settings'] as $tbl) {
    $exists = $pdo->query("SHOW TABLES LIKE '" . str_replace("'","''",$tbl) . "'")->fetchColumn();
    echo "  {$tbl}: " . ($exists ? "EXISTS" : "MISSING") . "\n";
}

// Check Admin tables
echo "\n--- Admin Tables ---\n";
foreach (['max_settings','max_groups','max_message_templates','max_send_log'] as $tbl) {
    $exists = $pdo->query("SHOW TABLES LIKE '" . str_replace("'","''",$tbl) . "'")->fetchColumn();
    echo "  {$tbl}: " . ($exists ? "EXISTS" : "MISSING") . "\n";
}

// Event Center templates
echo "\n--- Event Center Templates ---\n";
if ($pdo->query("SHOW TABLES LIKE 'max_event_templates'")->fetchColumn()) {
    $rows = qAll($pdo, "SELECT event_key, title, category, is_enabled, template_text FROM max_event_templates ORDER BY COALESCE(category,''), event_key");
    echo "Total: " . count($rows) . "\n";
    foreach ($rows as $r) {
        $enabled = ((int)($r['is_enabled']??0)===1) ? 'ON' : 'OFF';
        $cat = $r['category'] ?? '?';
        echo "  [{$enabled}] {$r['event_key']} ({$cat}): {$r['title']}\n";
    }
} else {
    echo "  Table max_event_templates not found.\n";
}

// Admin templates
echo "\n--- Admin Templates (max_message_templates) ---\n";
if ($pdo->query("SHOW TABLES LIKE 'max_message_templates'")->fetchColumn()) {
    $rows = qAll($pdo, "SELECT event_key, title, is_enabled FROM max_message_templates ORDER BY event_key");
    echo "Total: " . count($rows) . "\n";
    foreach ($rows as $r) {
        $enabled = ((int)($r['is_enabled']??0)===1) ? 'ON' : 'OFF';
        echo "  [{$enabled}] {$r['event_key']}: {$r['title']}\n";
    }
} else {
    echo "  Table max_message_templates not found.\n";
}

// Runtime settings
echo "\n--- Runtime Settings ---\n";
if ($pdo->query("SHOW TABLES LIKE 'system_runtime_settings'")->fetchColumn()) {
    $rows = qAll($pdo, "SELECT * FROM system_runtime_settings LIMIT 20");
    foreach ($rows as $r) {
        $keyCol = $r['setting_key'] ?? $r['key_name'] ?? $r['key'] ?? '?';
        $valCol = $r['setting_value'] ?? $r['value'] ?? $r['setting_val'] ?? '?';
        echo "  {$keyCol} = {$valCol}\n";
    }
}

// Global settings
echo "\n--- Global Settings (max_settings) ---\n";
if ($pdo->query("SHOW TABLES LIKE 'max_settings'")->fetchColumn()) {
    $rows = qAll($pdo, "SELECT * FROM max_settings");
    foreach ($rows as $r) {
        echo "  {$r['setting_key']} = {$r['setting_value']}\n";
    }
}

// MAX groups
echo "\n--- MAX Groups ---\n";
if ($pdo->query("SHOW TABLES LIKE 'max_groups'")->fetchColumn()) {
    $rows = qAll($pdo, "SELECT * FROM max_groups ORDER BY is_default DESC, id DESC");
    echo "Total: " . count($rows) . "\n";
    foreach ($rows as $r) {
        $def = ((int)($r['is_default']??0)===1) ? 'DEFAULT' : '';
        $act = ((int)($r['is_active']??0)===1) ? 'ACTIVE' : 'OFF';
        echo "  #{$r['id']} [{$act}] [{$def}] {$r['title']} => group_id={$r['group_id']}\n";
    }
}

// API config
echo "\n--- API Config ---\n";
$cfg = mapGetDirectMaxConfig();
echo "  base_url: " . ($cfg['base_url'] ?: 'EMPTY') . "\n";
echo "  token: " . ($cfg['token'] ? (substr($cfg['token'],0,8).'...') : 'EMPTY') . "\n";
echo "  chat_id: " . ($cfg['chat_id'] ?: 'EMPTY') . "\n";

// Test send
echo "\n--- Test Send ---\n";
$testMsg = "MAX diagnostic test: " . date('Y-m-d H:i:s');
$result = sendMaxNotify($testMsg, 'markdown', [
    'event_key' => 'test_message',
    'context' => ['message' => $testMsg],
]);
echo "  success: " . ($result['success'] ? 'true' : 'false') . "\n";
echo "  error: " . ($result['error'] ?? 'none') . "\n";
echo "  skipped: " . ($result['skipped'] ?? 'false') . "\n";
echo "  queued: " . ($result['queued'] ?? 'false') . "\n";

// Latest logs
echo "\n--- Latest max_send_log (5) ---\n";
if ($pdo->query("SHOW TABLES LIKE 'max_send_log'")->fetchColumn()) {
    $logs = qAll($pdo, "SELECT event_key, success, created_at FROM max_send_log ORDER BY id DESC LIMIT 5");
    foreach ($logs as $l) {
        $ok = (int)($l['success']??0)===1 ? 'OK' : 'ERR';
        echo "  [{$ok}] {$l['event_key']} at {$l['created_at']}\n";
    }
}

echo "\n--- Latest max_logs (5) ---\n";
if ($pdo->query("SHOW TABLES LIKE 'max_logs'")->fetchColumn()) {
    $logs = qAll($pdo, "SELECT event_key, success, status, created_at FROM max_logs ORDER BY id DESC LIMIT 5");
    foreach ($logs as $l) {
        $ok = (int)($l['success']??0)===1 ? 'OK' : 'ERR';
        echo "  [{$ok}] [{$l['status']}] {$l['event_key']} at {$l['created_at']}\n";
    }
}

echo "\n=== DONE ===\n";
