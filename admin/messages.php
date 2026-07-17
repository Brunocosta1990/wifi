<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();
$orgId=(int)$_SESSION['organization_id'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{csrf_check($_POST['csrf_token']??null);$id=(int)($_POST['id']??0);$action=$_POST['action']??'';
  $check=db()->prepare('SELECT m.id,m.status FROM messages m JOIN events e ON e.id=m.event_id WHERE m.id=? AND e.organization_id=?');$check->execute([$id,$orgId]);$m=$check->fetch();if(!$m)throw new RuntimeException('Mensagem não encontrada.');
  if($action==='cancel' && in_array($m['status'],['scheduled','draft'],true)){db()->prepare("UPDATE messages SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);audit('cancel','messages',$id);flash('success','Mensagem cancelada.');}
  elseif($action==='process' && $m['status']==='scheduled'){Scheduler::processMessage($id);flash('success','Disparo processado.');}
  else throw new RuntimeException('Ação não permitida para o status atual.');
 }catch(Throwable $e){flash('error',$e->getMessage());}redirect('admin/messages.php');
}
$stmt=db()->prepare("SELECT m.*,e.name event_name, (SELECT COUNT(*) FROM message_deliveries md WHERE md.message_id=m.id AND md.received_at IS NOT NULL) received_count FROM messages m JOIN events e ON e.id=m.event_id WHERE e.organization_id=? ORDER BY m.created_at DESC LIMIT 500");$stmt->execute([$orgId]);$messages=$stmt->fetchAll();
admin_header('Mensagens','messages');
?>
<div class="toolbar"><a class="btn btn-primary" href="<?= e(app_url('admin/message_form.php')) ?>">Criar mensagem</a><a class="btn btn-secondary" href="<?= e(app_url('cron_web.php?token='.urlencode(app_config()['cron_token']))) ?>" target="_blank">Executar agendador agora</a></div>
<section class="card panel"><div class="table-wrap"><table class="data-table"><thead><tr><th>Mensagem</th><th>Evento</th><th>Programada</th><th>Status</th><th>Resultado</th><th></th></tr></thead><tbody>
<?php if(!$messages):?><tr><td colspan="6" class="empty-state">Nenhuma mensagem criada.</td></tr><?php endif;?>
<?php foreach($messages as $m):?><tr><td><strong><?= e($m['title']) ?></strong><br><span class="muted"><?= e(mb_strimwidth($m['body'],0,85,'…')) ?></span></td><td><?= e($m['event_name']) ?></td><td><?= e(local_from_utc($m['scheduled_at'],$m['timezone'])) ?></td><td><span class="badge <?= e($m['status']) ?>"><?= e($m['status']) ?></span></td><td><?= (int)$m['total_targets'] ?> destinos<br><span class="muted"><?= (int)$m['total_success'] ?> aceitos · <?= (int)($m['received_count']??0) ?> recebidos · <?= (int)$m['total_failed'] ?> falhas</span></td><td><div class="actions"><?php if($m['status']==='scheduled'):?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-primary btn-small" name="action" value="process" type="submit">Enviar agora</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-danger btn-small" data-confirm="Cancelar esta mensagem?" name="action" value="cancel" type="submit">Cancelar</button></form><?php endif;?></div></td></tr><?php endforeach;?>
</tbody></table></div></section>
<?php admin_footer();?>
