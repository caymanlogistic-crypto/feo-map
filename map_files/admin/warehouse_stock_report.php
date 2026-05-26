<?php
session_start();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/common.php';

if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); echo 'Database connection error'; exit; }

// ── Helpers ──────────────────────────────────────────────────────────────────
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function gi(string $k, $d=''){ return isset($_GET[$k])?trim((string)$_GET[$k]):$d; }
function gint(string $k, int $d=0){ $v=gi($k); return preg_match('/^\d+$/',$v)?(int)$v:$d; }

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!maxAdminIsAuthed() && ($_SERVER['REQUEST_METHOD']??'GET')==='POST' && gi('action')==='login') {
    if (hash_equals(maxAdminPasswordConst(), gi('password'))) { $_SESSION['max_admin_auth']=1; header('Location: warehouse_stock_report.php'); exit; }
    $flash='Неверный пароль'; $flashType='err';
}
if (maxAdminIsAuthed() && gi('logout')==='1') { unset($_SESSION['max_admin_auth']); session_destroy(); header('Location: warehouse_stock_report.php'); exit; }

// ── Filters ──────────────────────────────────────────────────────────────────
$fWh = gint('warehouse_id');
$fFkko = gi('fkko');
$fZid = gi('zayavka_id');
$fFid = gint('flight_id');
$fFrom = gi('date_from'); if($fFrom!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fFrom)) $fFrom='';
$fTo = gi('date_to'); if($fTo!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fTo)) $fTo='';
$fShow = gi('show_mode','stock'); if(!in_array($fShow,['stock','all','moves'])) $fShow='stock';
$fLimit = gint('limit',200); if(!in_array($fLimit,[100,200,500])) $fLimit=200;
$fDetail = gint('detail_key',0); // warehouse_id:fkko:zayavka_id

// ── Warehouse list ───────────────────────────────────────────────────────────
$whList = [];
try { foreach($pdo->query('SELECT id,name FROM warehouses ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) as $r) $whList[(int)$r['id']]=$r['name']; } catch(Throwable $e){}

// ── Stock Query ──────────────────────────────────────────────────────────────
$stockRows = []; $summary = ['warehouses'=>0,'rows'=>0,'netto'=>0,'brutto'=>0,'vol'=>0,'neg'=>0];
if (maxAdminIsAuthed()) {
    $where = ["wm.status='active'"]; $params=[];
    if($fWh>0) { $where[]='wm.warehouse_id=:wh'; $params[':wh']=$fWh; }
    if($fFkko!=='') { $where[]='wm.fkko_code LIKE :fk'; $params[':fk']='%'.$fFkko.'%'; }
    if($fZid!=='') { $where[]='wm.zayavka_id=:zid'; $params[':zid']=(int)$fZid; }
    if($fFid>0) { $where[]='wm.flight_id=:fid'; $params[':fid']=$fFid; }
    if($fFrom!=='') { $where[]='wm.movement_date>=:df'; $params[':df']=$fFrom.' 00:00:00'; }
    if($fTo!=='') { $where[]='wm.movement_date<=:dt'; $params[':dt']=$fTo.' 23:59:59'; }
    $whereSql = implode(' AND ',$where);

    $sql = "SELECT wm.warehouse_id, wm.fkko_code, wm.zayavka_id,
                   SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_netto,0) ELSE 0 END) AS in_netto,
                   SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_netto,0) ELSE 0 END) AS out_netto,
                   SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.mass_brutto,0) ELSE 0 END) AS in_brutto,
                   SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.mass_brutto,0) ELSE 0 END) AS out_brutto,
                   SUM(CASE WHEN wm.movement_type IN ('receipt','transfer_in') THEN COALESCE(wm.volume,0) ELSE 0 END) AS in_vol,
                   SUM(CASE WHEN wm.movement_type IN ('issue','transfer_out') THEN COALESCE(wm.volume,0) ELSE 0 END) AS out_vol,
                   MAX(wm.movement_date) AS last_move
            FROM warehouse_movements wm
            WHERE {$whereSql}
            GROUP BY wm.warehouse_id, wm.fkko_code, wm.zayavka_id
            ORDER BY wm.warehouse_id, wm.fkko_code, wm.zayavka_id
            LIMIT {$fLimit}";
    try {
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($all as $r) {
            $n = round((float)$r['in_netto']-(float)$r['out_netto'],4);
            $b = round((float)$r['in_brutto']-(float)$r['out_brutto'],4);
            $v = round((float)$r['in_vol']-(float)$r['out_vol'],4);
            $r['stock_netto']=$n; $r['stock_brutto']=$b; $r['stock_vol']=$v;
            if($fShow==='stock' && $n==0 && $b==0 && $v==0) continue;
            $stockRows[]=$r;
        }
        $whSet=[]; foreach($stockRows as $r){$whSet[(int)$r['warehouse_id']]=true;}
        $summary['warehouses']=count($whSet); $summary['rows']=count($stockRows);
        foreach($stockRows as $r){$summary['netto']+=$r['stock_netto'];$summary['brutto']+=$r['stock_brutto'];$summary['vol']+=$r['stock_vol'];if($r['stock_netto']<0||$r['stock_brutto']<0||$r['stock_vol']<0)$summary['neg']++;}
        $summary['netto']=round($summary['netto'],4);$summary['brutto']=round($summary['brutto'],4);$summary['vol']=round($summary['vol'],4);
    } catch(Throwable $e) { $flash='Ошибка запроса: '.$e->getMessage(); $flashType='err'; }
}

// ── Detail rows ──────────────────────────────────────────────────────────────
$detailRows = [];
if ($fDetail>0 && maxAdminIsAuthed()) {
    $parts = explode(':',(string)$fDetail);
    $dWh = (int)($parts[0]??0); $dFkko = (string)($parts[1]??''); $dZid = (int)($parts[2]??0);
    if ($dWh>0) {
        try {
            $stmt = $pdo->prepare("SELECT wm.*, COALESCE(feo.naim_otkhoda_fkko, '') AS feo_name FROM warehouse_movements wm LEFT JOIN feo ON feo.zayavka_id=wm.zayavka_id WHERE wm.warehouse_id=:wh AND wm.fkko_code=:fk AND wm.zayavka_id=:zid AND wm.status='active' ORDER BY wm.movement_date DESC, wm.id DESC LIMIT 200");
            $stmt->execute([':wh'=>$dWh,':fk'=>$dFkko,':zid'=>$dZid]);
            $detailRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){}
    }
}

function whName(array $list, int $id): string { return isset($list[$id])?$list[$id]:('Склад #'.$id); }
function moveLabel(string $t): string { return ['receipt'=>'Приход на склад','transfer_out'=>'Перемещение: списание','transfer_in'=>'Перемещение: поступление','issue'=>'Вывоз со склада'][$t]??$t; }
function flightAction(string $t, int $fid): string {
    $m = ['receipt'=>"Рейс #{$fid} привёз груз на склад",'transfer_out'=>"Рейс #{$fid} забрал груз со склада",'transfer_in'=>"Рейс #{$fid} привёз груз после перемещения",'issue'=>"Рейс #{$fid} забрал груз на утилизацию"];
    return $m[$t]??"Рейс #{$fid}";
}
function badge(float $v): string {
    if ($v>0.0001) return '<span class="badge badge-ok">'.rtrim(rtrim(number_format($v,4,'.',''),'0'),'.').'</span>';
    if ($v<-0.0001) return '<span class="badge badge-bad">⚠ '.rtrim(rtrim(number_format($v,4,'.',''),'0'),'.').'</span>';
    return '<span class="badge badge-zero">0</span>';
}
?><!doctype html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Складские остатки</title>
<style>
body{margin:0;background:#f4f6f8;color:#1e293b;font-family:Arial,sans-serif}
.wrap{max-width:calc(100vw - 48px);margin:0 auto;padding:12px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.card{background:#fff;border:1px solid #d9e0e7;border-radius:8px;padding:14px;margin-bottom:10px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
label{font-size:13px;color:#334155}
input[type=text],input[type=number],input[type=date],select{border:1px solid #cbd5e1;border-radius:6px;padding:6px 8px;font-size:13px;background:#fff;color:#1e293b}
.btn{border:1px solid #94a3b8;background:#eef2f7;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;height:32px;display:inline-flex;align-items:center;text-decoration:none;color:#1e293b}
.btn.primary{background:#0ea5b7;color:#fff;border-color:#0b7285}
.small{font-size:12px;color:#64748b}
.ok{background:#ecfdf3;border-color:#b7e4c7;color:#0f766e}
.err{background:#fef2f2;border-color:#fecaca;color:#b42318}
.warn-bg{background:#fffbeb;border-color:#fde68a;color:#92400e}
table{width:100%;border-collapse:collapse}
th,td{font-size:12px;border-bottom:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}
th{background:#f8fafc;color:#475569;font-weight:600}
tr:hover{background:#f8fafc}
.mono{font-family:Consolas,monospace;font-size:12px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.badge-ok{background:#dcfce7;color:#166534}
.badge-bad{background:#fee2e2;color:#b42318}
.badge-zero{background:#f1f5f9;color:#64748b}
.badge-info{background:#dbeafe;color:#1e40af}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:10px}
.summary-item{text-align:center;padding:12px;border-radius:8px;background:#fff;border:1px solid #e2e8f0}
.summary-item .num{font-size:24px;font-weight:700}
.summary-item .lbl{font-size:11px;color:#64748b;margin-top:2px}
.login{max-width:420px;margin:80px auto}
.zayavki-col{max-width:800px;min-width:200px;white-space:normal;overflow-wrap:anywhere;word-break:break-word;line-height:1.35}
.detail-table td,.detail-table th{font-size:11px;padding:4px 8px}
@media(max-width:900px){.summary-grid{grid-template-columns:1fr 1fr}}
</style></head><body><div class="wrap">
<div class="top"><h2 style="margin:0;font-size:18px">Складские остатки</h2><?php renderAdminNav('stock'); ?></div>

<?php if(!empty($flash)): ?><div class="card <?=$flashType==='err'?'err':'ok'?>"><?=h($flash)?></div><?php endif; ?>

<?php if(!maxAdminIsAuthed()): ?>
<div class="card login"><form method="post"><input type="hidden" name="action" value="login"><label>Пароль доступа</label><input type="password" name="password" required style="width:100%"><div style="height:8px"></div><button class="btn primary" type="submit">Войти</button></form></div>
<?php else: ?>

<!-- Filters -->
<div class="card">
  <form method="get">
    <div class="row" style="flex-wrap:wrap;gap:6px">
      <label>Склад <select name="warehouse_id"><option value="0">Все склады</option><?php foreach($whList as $wid=>$wname):?><option value="<?=$wid?>" <?=$fWh===$wid?'selected':''?>><?=h($wname)?></option><?php endforeach;?></select></label>
      <label>ФККО <input type="text" name="fkko" value="<?=h($fFkko)?>" placeholder="поиск" style="width:100px"></label>
      <label>Заявка ID <input type="text" name="zayavka_id" value="<?=h($fZid)?>" placeholder="ID" style="width:80px"></label>
      <label>Рейс ID <input type="number" name="flight_id" value="<?=$fFid>0?$fFid:''?>" placeholder="ID" style="width:80px"></label>
      <label>С <input type="date" name="date_from" value="<?=h($fFrom)?>" style="width:130px"></label>
      <label>По <input type="date" name="date_to" value="<?=h($fTo)?>" style="width:130px"></label>
      <label>Показ <select name="show_mode"><option value="stock" <?=$fShow==='stock'?'selected':''?>>Остатки > 0</option><option value="all" <?=$fShow==='all'?'selected':''?>>Все строки</option><option value="moves" <?=$fShow==='moves'?'selected':''?>>Только движения</option></select></label>
      <label>Лимит <select name="limit"><option value="100" <?=$fLimit===100?'selected':''?>>100</option><option value="200" <?=$fLimit===200?'selected':''?>>200</option><option value="500" <?=$fLimit===500?'selected':''?>>500</option></select></label>
      <button class="btn primary" type="submit">Показать</button>
      <?php if($fDetail>0):?><input type="hidden" name="detail_key" value="<?=$fDetail?>"><?php endif;?>
    </div>
  </form>
</div>

<!-- Summary -->
<div class="summary-grid">
  <div class="summary-item"><div class="num"><?=$summary['warehouses']?></div><div class="lbl">Складов с остатками</div></div>
  <div class="summary-item"><div class="num"><?=$summary['rows']?></div><div class="lbl">Строк остатков</div></div>
  <div class="summary-item"><div class="num"><?=rtrim(rtrim(number_format($summary['netto'],4,'.',''),'0'),'.')?></div><div class="lbl">Остаток нетто, т</div></div>
  <div class="summary-item"><div class="num"><?=rtrim(rtrim(number_format($summary['brutto'],4,'.',''),'0'),'.')?></div><div class="lbl">Остаток брутто, т</div></div>
  <div class="summary-item"><div class="num"><?=rtrim(rtrim(number_format($summary['vol'],4,'.',''),'0'),'.')?></div><div class="lbl">Остаток объём, м³</div></div>
  <div class="summary-item" style="border-color:<?=$summary['neg']>0?'#fecaca':'#e2e8f0'?>"><div class="num" style="color:<?=$summary['neg']>0?'#b42318':'#1e293b'?>"><?=$summary['neg']?></div><div class="lbl">Отрицательных</div></div>
</div>
<?php if($summary['neg']>0):?><div class="card warn-bg">⚠ Внимание: есть отрицательные остатки (<?=$summary['neg']?> строк). Проверьте складские движения.</div><?php endif;?>

<!-- Stock Table -->
<div class="card">
  <div class="row" style="justify-content:space-between;margin-bottom:8px"><strong>Остатки по складам</strong><span class="small">Группировка: склад, ФККО, заявка. Статус движений: active.</span></div>
  <?php if(empty($stockRows)):?><p class="small">Нет данных по выбранным фильтрам.</p>
  <?php else:?>
  <div style="overflow-x:auto">
  <table>
    <thead><tr>
      <th>Склад</th><th>ФККО</th><th>Заявка ID</th><th>Приход нетто</th><th>Расход нетто</th><th>Остаток нетто</th><th>Остаток брутто</th><th>Остаток объём</th><th>Последнее</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach($stockRows as $r):
      $key = $r['warehouse_id'].':'.$r['fkko_code'].':'.$r['zayavka_id'];
      $isActive = ($fDetail>0 && (string)$fDetail===$key);
    ?>
    <tr style="<?=$isActive?'background:#eef7ff':''?>">
      <td><?=h(whName($whList,(int)$r['warehouse_id']))?></td>
      <td class="mono" style="font-size:10px"><?=h($r['fkko_code']?:'ФККО не указан')?></td>
      <td class="mono"><?=(int)$r['zayavka_id']?></td>
      <td><?=rtrim(rtrim(number_format((float)$r['in_netto'],4,'.',''),'0'),'.')?></td>
      <td><?=rtrim(rtrim(number_format((float)$r['out_netto'],4,'.',''),'0'),'.')?></td>
      <td><?=badge($r['stock_netto'])?></td>
      <td><?=badge($r['stock_brutto'])?></td>
      <td><?=badge($r['stock_vol'])?></td>
      <td class="small"><?=h($r['last_move']?date('d.m.Y',strtotime($r['last_move'])):'—')?></td>
      <td><a class="btn" style="height:26px;font-size:11px;padding:2px 8px" href="?<?=http_build_query(array_filter(['warehouse_id'=>$fWh,'fkko'=>$fFkko,'zayavka_id'=>$fZid,'flight_id'=>$fFid,'date_from'=>$fFrom,'date_to'=>$fTo,'show_mode'=>$fShow,'limit'=>$fLimit,'detail_key'=>$isActive?0:$key]))?>"><?=$isActive?'Закрыть':'Детали'?></a></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
  <?php endif;?>
</div>

<!-- Detail Table -->
<?php if($fDetail>0 && !empty($detailRows)):
  $dParts=explode(':',(string)$fDetail);
?>
<div class="card">
  <div class="row" style="justify-content:space-between;margin-bottom:8px"><strong>Детали движений: <?=h(whName($whList,(int)$dParts[0]))?> / <?=h($dParts[1]?:'ФККО не указан')?> / Заявка #<?=(int)($dParts[2]??0)?></strong><span class="small"><?=count($detailRows)?> записей</span></div>
  <div style="overflow-x:auto">
  <table class="detail-table">
    <thead><tr><th>Дата</th><th>Тип</th><th>Рейс</th><th>Действие рейса</th><th>Заявка</th><th>ФККО</th><th>Нетто</th><th>Брутто</th><th>Объём</th><th>Склад отпр.</th><th>Склад назн.</th><th>Комментарий</th></tr></thead>
    <tbody>
    <?php foreach($detailRows as $dr):?>
    <tr>
      <td class="small"><?=h($dr['movement_date']?date('d.m.Y H:i',strtotime($dr['movement_date'])):'—')?></td>
      <td><span class="badge badge-info"><?=moveLabel($dr['movement_type'])?></span></td>
      <td class="mono"><?=(int)$dr['flight_id']?></td>
      <td class="small"><?=h(flightAction($dr['movement_type'],(int)$dr['flight_id']))?></td>
      <td class="mono"><?=(int)$dr['zayavka_id']?></td>
      <td class="mono" style="font-size:10px"><?=h($dr['fkko_code']?:'—')?></td>
      <td><?=rtrim(rtrim(number_format((float)($dr['mass_netto']??0),4,'.',''),'0'),'.')?></td>
      <td><?=rtrim(rtrim(number_format((float)($dr['mass_brutto']??0),4,'.',''),'0'),'.')?></td>
      <td><?=rtrim(rtrim(number_format((float)($dr['volume']??0),4,'.',''),'0'),'.')?></td>
      <td><?=h(whName($whList,(int)($dr['source_warehouse_id']??0)))?></td>
      <td><?=h(whName($whList,(int)($dr['destination_warehouse_id']??0)))?></td>
      <td class="small"><?=h($dr['comment']??'')?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>
<?php endif;?>

<?php endif; // authed ?>
</div></body></html>
