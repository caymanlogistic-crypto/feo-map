<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Support/max_notify.php';

function ce($pdo,$tbl,$col){static $c=[];$k="$tbl.$col";if(isset($c[$k]))return $c[$k];$s=$pdo->query("SHOW COLUMNS FROM `".str_replace('`','``',$tbl)."`");$r=$s?$s->fetchAll(PDO::FETCH_COLUMN):[];$c[$k]=in_array($col,$r);return $c[$k];}

echo "=== DRIVER TEMPLATE CHECK ===\n\n";

// max_event_templates
echo "--- max_event_templates ---\n";
$ecCols=['event_key','title']; foreach(['category','cat'] as $c) if(ce($pdo,'max_event_templates',$c)){$ecCols[]=$c;break;} foreach(['is_enabled','enabled','active','is_active'] as $c) if(ce($pdo,'max_event_templates',$c)){$ecCols[]=$c;break;} $ecCols[]='template_text';
$r=$pdo->query("SELECT ".implode(',',$ecCols)." FROM max_event_templates WHERE event_key='driver_new_tracker_configured'")->fetchAll(PDO::FETCH_ASSOC);
foreach($r as $l){
  $en='?';foreach(['is_enabled','enabled','active','is_active'] as $c) if(isset($l[$c])){$en=((int)$l[$c]===1?'ON':'OFF');break;}
  $cat='';foreach(['category','cat'] as $c) if(isset($l[$c])){$cat=$l[$c];break;}
  echo "#{$l['id']} {$l['event_key']} [{$en}] {$cat}: {$l['title']}\n  template: ".mb_substr($l['template_text'],0,300)."\n";
}

echo "\n--- max_message_templates ---\n";
$mcCols=['event_key','title']; foreach(['is_enabled','enabled'] as $c) if(ce($pdo,'max_message_templates',$c)){$mcCols[]=$c;break;} $mcCols[]='template_text';
$r2=$pdo->query("SELECT ".implode(',',$mcCols)." FROM max_message_templates WHERE event_key='driver_new_tracker_configured'")->fetchAll(PDO::FETCH_ASSOC);
foreach($r2 as $l){
  $en='?';foreach(['is_enabled','enabled'] as $c) if(isset($l[$c])){$en=((int)$l[$c]===1?'ON':'OFF');break;}
  echo "#{$l['id']} {$l['event_key']} [{$en}]: {$l['title']}\n  template: ".mb_substr($l['template_text'],0,300)."\n";
}
echo "EC=".count($r)." Admin=".count($r2)."\n";

// Render test
echo "\n--- Render test ---\n";
$ctx=['driver'=>'TEST DRIVER','tracker_uniqueid'=>'190039','feo_params'=>'123456 31.41.245.15:10364 Wialon','outID'=>'123456','outIP'=>'31.41.245.15','outPort'=>'10364','outProtocol'=>'Wialon','driver_label'=>'TEST DRIVER','tracker_id'=>'190039','tracker_name'=>'TEST DRIVER','feo_line'=>'123456 31.41.245.15:10364 Wialon'];
if(count($r)>0){
  $rendered=mapAdminRenderTemplate($r[0]['template_text'],$ctx);
  echo "RENDERED: {$rendered}\n";
  if(strpos($rendered,'123456')!==false) echo "PASS: feo_params OK\n"; else echo "FAIL: feo_params not in render\n";
}

// Synthetic test
echo "\n--- resolveFeoParams synthetic ---\n";
require_once __DIR__ . '/../save_driver.php';
$tests=[
  'A'=>['outID'=>'111','outIP'=>'1.1.1.1','outPort'=>'1000','outProtocol'=>'Wialon'],
  'B'=>['device'=>['data'=>['external'=>['output_id'=>'222','output_ip'=>'2.2.2.2','output_port'=>'2000','output_protocol'=>'Wialon']]]],
  'C'=>['retransmission'=>['retransmission_id'=>'333','retransmission_ip'=>'3.3.3.3','retransmission_port'=>'3000','retransmission_protocol'=>'Wialon']],
];
foreach($tests as $k=>$v){$p=resolveFeoParams($v,null);echo "{$k}: line={$p['line']}\n"; if($p['line']==='н/д') echo "  FAIL!\n";}

echo "\n=== DONE ===\n";
