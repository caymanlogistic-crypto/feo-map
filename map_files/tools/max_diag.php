<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/max_notify.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { echo "ERROR: No PDO\n"; exit(1); }

function qAll($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);if(!$s)return[];$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
function colExists($pdo,$tbl,$col){static $cache=[];$k="$tbl.$col";if(isset($cache[$k]))return $cache[$k];$s=$pdo->query("SHOW COLUMNS FROM `".str_replace('`','``',$tbl)."`");$r=$s?$s->fetchAll(PDO::FETCH_COLUMN):[];$cache[$k]=in_array($col,$r);return $cache[$k];}
function tblExists($pdo,$tbl){static $cache=[];if(isset($cache[$tbl]))return $cache[$tbl];$s=$pdo->query("SHOW TABLES LIKE '".str_replace("'","''",$tbl)."'");$cache[$tbl]=(bool)$s->fetchColumn();return $cache[$tbl];}

echo "=== MAX DIAGNOSTIC ===\n\n";

// Tables check
echo "--- Tables ---\n";
$allTables = ['max_event_templates','max_groups','max_logs','max_pending_queue','system_runtime_settings','max_settings','max_message_templates','max_send_log'];
foreach ($allTables as $tbl) echo "  {$tbl}: " . (tblExists($pdo,$tbl)?'EXISTS':'MISSING') . "\n";

// Event Center templates
echo "\n--- Event Center Templates ---\n";
if (tblExists($pdo,'max_event_templates')) {
    $selCols = ['event_key','title'];
    foreach (['category','cat'] as $c) if (colExists($pdo,'max_event_templates',$c)) { $selCols[]=$c; break; }
    foreach (['is_enabled','enabled','active','is_active'] as $c) if (colExists($pdo,'max_event_templates',$c)) { $selCols[]=$c; break; }
    $sel = implode(',',$selCols);
    $rows = qAll($pdo, "SELECT {$sel} FROM max_event_templates ORDER BY event_key");
    echo "Total: " . count($rows) . "\n";
    foreach ($rows as $r) {
        $enabled = '?'; foreach (['is_enabled','enabled','active','is_active'] as $c) if(isset($r[$c])){$enabled=((int)$r[$c]===1?'ON':'OFF');break;}
        $cat = ''; foreach(['category','cat'] as $c) if(isset($r[$c])){$cat=$r[$c];break;}
        echo "  [{$enabled}] {$r['event_key']}" . ($cat?" ({$cat})":'') . ": {$r['title']}\n";
    }
}

// Admin templates
echo "\n--- Admin Templates ---\n";
if (tblExists($pdo,'max_message_templates')) {
    $selCols = ['event_key','title'];
    foreach (['is_enabled','enabled'] as $c) if (colExists($pdo,'max_message_templates',$c)) { $selCols[]=$c; break; }
    $sel = implode(',',$selCols);
    $rows = qAll($pdo, "SELECT {$sel} FROM max_message_templates ORDER BY event_key");
    echo "Total: " . count($rows) . "\n";
    foreach ($rows as $r) {
        $enabled = '?'; foreach(['is_enabled','enabled'] as $c) if(isset($r[$c])){$enabled=((int)$r[$c]===1?'ON':'OFF');break;}
        echo "  [{$enabled}] {$r['event_key']}: {$r['title']}\n";
    }
}

// Runtime settings
echo "\n--- Runtime Settings ---\n";
if (tblExists($pdo,'system_runtime_settings')) {
    $keyCol = colExists($pdo,'system_runtime_settings','setting_key')?'setting_key':(colExists($pdo,'system_runtime_settings','key_name')?'key_name':(colExists($pdo,'system_runtime_settings','key')?'key':''));
    $valCol = colExists($pdo,'system_runtime_settings','setting_value')?'setting_value':(colExists($pdo,'system_runtime_settings','value')?'value':(colExists($pdo,'system_runtime_settings','setting_val')?'setting_val':''));
    if ($keyCol && $valCol) {
        $rows = qAll($pdo, "SELECT `{$keyCol}` AS k, `{$valCol}` AS v FROM system_runtime_settings LIMIT 20");
        foreach ($rows as $r) echo "  {$r['k']} = {$r['v']}\n";
    }
}

// Global settings
echo "\n--- Global Settings ---\n";
if (tblExists($pdo,'max_settings')) {
    $rows = qAll($pdo, "SELECT setting_key, setting_value FROM max_settings");
    foreach ($rows as $r) echo "  {$r['setting_key']} = {$r['setting_value']}\n";
}

// MAX groups
echo "\n--- MAX Groups ---\n";
if (tblExists($pdo,'max_groups')) {
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
if (tblExists($pdo,'max_send_log')) {
    $logs = qAll($pdo, "SELECT event_key, success, created_at FROM max_send_log ORDER BY id DESC LIMIT 5");
    foreach ($logs as $l) {
        $ok = (int)($l['success']??0)===1 ? 'OK' : 'ERR';
        echo "  [{$ok}] {$l['event_key']} at {$l['created_at']}\n";
    }
}

echo "\n--- Latest max_logs (5) ---\n";
if (tblExists($pdo,'max_logs')) {
    $selCols = ['event_key','status'];
    foreach (['success','is_success','ok'] as $c) if (colExists($pdo,'max_logs',$c)) { $selCols[]=$c; break; }
    foreach (['created_at','created','logged_at'] as $c) if (colExists($pdo,'max_logs',$c)) { $selCols[]=$c; break; }
    $sel = implode(',',$selCols);
    $logs = qAll($pdo, "SELECT {$sel} FROM max_logs ORDER BY id DESC LIMIT 5");
    foreach ($logs as $l) {
        $ok = '?'; foreach(['success','is_success','ok'] as $c) if(isset($l[$c])){$ok=((int)$l[$c]===1?'OK':'ERR');break;}
        $ts = ''; foreach(['created_at','created','logged_at'] as $c) if(isset($l[$c])){$ts=$l[$c];break;}
        echo "  [{$ok}] [{$l['status']}] {$l['event_key']} at {$ts}\n";
    }
}

echo "\n=== DONE ===\n";
