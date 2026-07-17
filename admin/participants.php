<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();
$orgId=(int)$_SESSION['organization_id'];
$eventId=(int)($_GET['event_id']??0);
$events=db()->prepare('SELECT id,name FROM events WHERE organization_id=? ORDER BY name');$events->execute([$orgId]);$eventList=$events->fetchAll();
$sql="SELECT p.*,e.name event_name,(SELECT COUNT(*) FROM push_subscriptions ps WHERE ps.participant_id=p.id AND ps.status='active') devices FROM participants p JOIN events e ON e.id=p.event_id WHERE e.organization_id=?";$params=[$orgId];
if($eventId){$sql.=' AND p.event_id=?';$params[]=$eventId;}$sql.=' ORDER BY p.created_at DESC LIMIT 500';
$stmt=db()->prepare($sql);$stmt->execute($params);$participants=$stmt->fetchAll();
admin_header('Participantes','participants');
?>
<form class="toolbar" method="get"><select name="event_id"><option value="0">Todos os eventos</option><?php foreach($eventList as $ev):?><option value="<?= (int)$ev['id'] ?>" <?= $eventId===(int)$ev['id']?'selected':'' ?>><?= e($ev['name']) ?></option><?php endforeach;?></select><button class="btn btn-secondary" type="submit">Filtrar</button></form>
<section class="card panel"><div class="table-wrap"><table class="data-table"><thead><tr><th>Participante</th><th>Evento</th><th>Contato</th><th>Aparelhos</th><th>Cadastro</th><th>Status</th></tr></thead><tbody>
<?php if(!$participants):?><tr><td colspan="6" class="empty-state">Nenhum participante encontrado.</td></tr><?php endif;?>
<?php foreach($participants as $p):?><tr><td><strong><?= e($p['name']) ?></strong><br><span class="muted"><?= e($p['participant_type']?:'Participante') ?></span></td><td><?= e($p['event_name']) ?></td><td><?= e($p['email']?:'—') ?><br><?= e($p['phone']?:'') ?></td><td><?= (int)$p['devices'] ?></td><td><?= e(local_from_utc($p['created_at'])) ?><br><span class="muted"><?= e($p['registration_ip']) ?></span></td><td><span class="badge <?= e($p['status']) ?>"><?= e($p['status']) ?></span></td></tr><?php endforeach;?>
</tbody></table></div></section>
<?php admin_footer();?>
