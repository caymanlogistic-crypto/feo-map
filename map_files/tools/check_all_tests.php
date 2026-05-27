<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../save_driver.php';

echo "=== Device 560201 structure ===\n";
$cfg=getSlitexConfig();
$r=slitexRequest('GET', $cfg['base_url'].'/api/external/devices', $cfg['token']);
if(!$r['success']){echo "FAIL: {$r['error']}\n";exit(1);}
$d=json_decode($r['body'],true);
$devs=isset($d['data'])?$d['data']:$d;
$t560=null;
foreach($devs as $dev){if(($dev['uniqueid']??'')==='560201'){$t560=$dev;break;}}
if(!$t560){echo "560201 NOT FOUND\n";exit(1);}
echo "Top keys: ".implode(', ',array_keys($t560))."\n";
function printKeys($data,$prefix='',$depth=0){if($depth>5||!is_array($data))return;foreach($data as $k=>$v){echo "{$prefix}{$k}";if(is_array($v)){echo " [array(".count($v).")]\n";printKeys($v,$prefix.'  ',$depth+1);}else{echo " = ".mb_substr((string)$v,0,80)."\n";}}}
printKeys($t560);
echo "\n=== resolveFeoParams on 560201 ===\n";
$fp=resolveFeoParams($t560,null);
echo "outID={$fp['outID']} outIP={$fp['outIP']} outPort={$fp['outPort']} outProtocol={$fp['outProtocol']}\nline={$fp['line']}\n";

// Try detail endpoint
echo "\n=== Device 560201 detail ===\n";
$r2=slitexRequest('GET', $cfg['base_url'].'/api/external/devices/560201', $cfg['token']);
if($r2['success']){
  $dd=json_decode($r2['body'],true);
  echo "Top keys: ".implode(', ',array_keys($dd))."\n";
  printKeys($dd);
}
echo "DONE\n";
