<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();
$orgId=(int)$_SESSION['organization_id'];
$events=db()->prepare("SELECT id,name,timezone,slug FROM events WHERE organization_id=? AND status IN ('active','draft') ORDER BY name");$events->execute([$orgId]);$eventList=$events->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{csrf_check($_POST['csrf_token']??null);$eventId=(int)($_POST['event_id']??0);$ev=db()->prepare('SELECT * FROM events WHERE id=? AND organization_id=?');$ev->execute([$eventId,$orgId]);$event=$ev->fetch();if(!$event)throw new RuntimeException('Selecione um evento válido.');
  $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));if($title===''||$body==='')throw new RuntimeException('Informe título e mensagem.');if(mb_strlen($title)>120||mb_strlen($body)>500)throw new RuntimeException('Título ou mensagem acima do limite.');
  $mode=$_POST['send_mode']??'scheduled';$local=$mode==='now'?(new DateTimeImmutable('now',new DateTimeZone($event['timezone'])))->format('Y-m-d H:i:s'):(string)($_POST['scheduled_at']??'');if($local==='')throw new RuntimeException('Informe a data e o horário.');$scheduledUtc=utc_from_local($local,$event['timezone']);
  $expiresMinutes=max(1,min(1440,(int)($_POST['expires_minutes']??60)));$expiresUtc=(new DateTimeImmutable($scheduledUtc,new DateTimeZone('UTC')))->modify('+'.$expiresMinutes.' minutes')->format('Y-m-d H:i:s');
  $actionUrl=trim((string)($_POST['action_url']??''));$tag=trim((string)($_POST['tag']??''));
  db()->prepare("INSERT INTO messages (event_id,created_by,title,body,action_url,tag,audience_type,scheduled_at,expires_at,timezone,status,created_at,updated_at) VALUES (?,?,?,?,?,?,'all',?,?,?,'scheduled',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$eventId,$_SESSION['admin_id'],$title,$body,$actionUrl?:null,$tag?:null,$scheduledUtc,$expiresUtc,$event['timezone']]);$id=(int)db()->lastInsertId();audit('create','messages',$id,['title'=>$title]);
  if($mode==='now'){Scheduler::processMessage($id);flash('success','Mensagem criada e processada para envio.');}else flash('success','Mensagem programada.');redirect('admin/messages.php');
 }catch(Throwable $e){flash('error',$e->getMessage());redirect('admin/message_form.php');}
}
$defaultEvent=(int)($_GET['event_id']??($eventList[0]['id']??0));
admin_header('Nova mensagem','messages');
?>
<div class="toolbar"><a class="btn btn-secondary" href="<?= e(app_url('admin/messages.php')) ?>">Voltar</a></div>
<section class="card form-card">
<form method="post" class="form-grid" id="message-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label>Evento<select name="event_id" required><?php foreach($eventList as $ev):?><option value="<?= (int)$ev['id'] ?>" <?= $defaultEvent===(int)$ev['id']?'selected':'' ?>><?= e($ev['name']) ?></option><?php endforeach;?></select></label>
<label>Identificador/tag<input name="tag" maxlength="80" placeholder="ex.: palestra-14h"><small>Notificações com a mesma tag podem substituir a anterior.</small></label>
<label class="span-2">Título<input name="title" required maxlength="120" placeholder="A próxima atividade começa em 10 minutos"></label>
<label class="span-2">Mensagem<textarea name="body" required maxlength="500" placeholder="Dirija-se ao Auditório Principal."></textarea></label>
<label class="span-2">Link ao tocar — opcional<input name="action_url" placeholder="https://... ou caminho interno"></label>
<label>Modo de envio<select name="send_mode" id="send-mode"><option value="scheduled">Programar horário</option><option value="now">Enviar agora</option></select></label>
<label id="schedule-field">Data e horário<input name="scheduled_at" type="datetime-local" value="<?= e(date('Y-m-d\TH:i',time()+300)) ?>"></label>
<label>Validade após o horário<input name="expires_minutes" type="number" min="1" max="1440" value="60"><small>Em minutos. Evita entregar um aviso atrasado.</small></label>
<div class="help-box span-2">O agendador verifica mensagens a cada minuto. Para envio automático, configure o Cron Job informado em Configurações.</div>
<button class="btn btn-primary span-2" type="submit">Salvar mensagem</button>
</form>
</section>
<script>const mode=document.getElementById('send-mode'),field=document.getElementById('schedule-field');function sync(){field.classList.toggle('hidden',mode.value==='now')}mode.addEventListener('change',sync);sync();</script>
<?php admin_footer();?>
