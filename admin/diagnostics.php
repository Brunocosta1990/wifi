<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php'; require dirname(__DIR__) . '/src/admin_layout.php'; Auth::requireAdmin();
function rowdiag(string $name,string $status,string $detail=''): string { return '<tr><td>'.e($name).'</td><td><span class="badge '.($status==='OK'?'active':($status==='Erro'?'blocked':'draft')).'">'.e($status).'</span></td><td>'.e($detail).'</td></tr>'; }
$items=[]; $cfg=app_config();
$items[]= ['PHP', version_compare(PHP_VERSION,'8.1','>=')?'OK':'Erro', PHP_VERSION];
$items[]= ['HTTPS', (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||str_starts_with($cfg['app_url'],'https://')?'OK':'Atenção', $cfg['app_url']];
foreach(['pdo_mysql','openssl','curl','json','mbstring'] as $ext) $items[]=[$ext, extension_loaded($ext)?'OK':'Erro', extension_loaded($ext)?'carregada':'ausente'];
$items[]= ['storage', is_writable(dirname(__DIR__).'/storage')?'OK':'Erro', dirname(__DIR__).'/storage'];
$items[]= ['logs', is_writable(dirname(__DIR__).'/storage/logs')||@mkdir(dirname(__DIR__).'/storage/logs',0775,true)?'OK':'Erro','storage/logs'];
$items[]= ['timezone', date_default_timezone_get()==='America/Sao_Paulo'?'OK':'Atenção', date_default_timezone_get()];
try{ $pdo=db(); $items[]=['Banco conexão','OK',$pdo->query('SELECT VERSION()')->fetchColumn()]; $items[]=['Charset conexão','OK',$pdo->query("SELECT @@character_set_connection, @@collation_connection")->fetchColumn()]; }catch(Throwable $e){ $items[]=['Banco conexão','Erro',$e->getMessage()]; }
try{ $q=db()->query("SELECT COUNT(*) FROM messages WHERE status='processing'"); $items[]=['Mensagens presas',(int)$q->fetchColumn()===0?'OK':'Atenção','processing']; }catch(Throwable $e){}
try{ $q=db()->query("SELECT COUNT(*) FROM push_queue WHERE status='pending'"); $items[]=['Fila','OK',(string)$q->fetchColumn().' pendentes']; }catch(Throwable $e){}
$v=$cfg['vapid']??[]; $items[]=['VAPID pública',!empty($v['public_key'])?'OK':'Erro',!empty($v['public_key'])?'configurada':'ausente']; $items[]=['VAPID privada',!empty($v['private_key_pem'])?'OK':'Erro',!empty($v['private_key_pem'])?'configurada':'ausente']; $items[]=['VAPID subject',!empty($v['subject'])?'OK':'Atenção',$v['subject']??''];
foreach(['manifest.php','sw.js','assets/icons/icon-192.png','assets/icons/icon-512.png'] as $f) $items[]=[$f,is_file(dirname(__DIR__).'/'.$f)?'OK':'Erro',$f];
try{ $hb=db()->query('SELECT * FROM cron_heartbeat WHERE id=1')->fetch(); $items[]=['Cron última execução',$hb?'OK':'Atenção',$hb['last_finished_at']??'sem registro']; $items[]=['Cron último erro',empty($hb['last_error'])?'OK':'Erro',$hb['last_error']??'']; }catch(Throwable $e){ $items[]=['Cron heartbeat','Atenção',$e->getMessage()]; }
admin_header('Diagnóstico do sistema','diagnostics'); ?>
<div class="toolbar"><a class="btn btn-primary" href="<?= e(app_url('admin/logs.php')) ?>">Abrir logs</a></div><section class="card panel"><h2>Ambiente, Banco, Web Push, PWA e Cron</h2><div class="table-wrap"><table class="data-table"><tr><th>Item</th><th>Status</th><th>Detalhe</th></tr><?php foreach($items as $i) echo rowdiag($i[0],$i[1],$i[2]); ?></table></div></section>
<?php admin_footer(); ?>
