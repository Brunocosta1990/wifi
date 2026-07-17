<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();
$orgId = (int) $_SESSION['organization_id'];
$stmt = db()->prepare("SELECT e.*,
 (SELECT COUNT(*) FROM participants p WHERE p.event_id=e.id) participants_count,
 (SELECT COUNT(*) FROM push_subscriptions ps WHERE ps.event_id=e.id AND ps.status='active') subscriptions_count
 FROM events e WHERE organization_id=? ORDER BY created_at DESC");
$stmt->execute([$orgId]);
$events = $stmt->fetchAll();
admin_header('Eventos', 'events');
?>
<div class="toolbar"><a class="btn btn-primary" href="<?= e(app_url('admin/event_form.php')) ?>">Criar evento</a></div>
<section class="card panel"><div class="table-wrap"><table class="data-table"><thead><tr><th>Evento</th><th>Local / Wi-Fi</th><th>Período</th><th>Público</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$events): ?><tr><td colspan="6" class="empty-state">Crie o primeiro evento para gerar o link e o QR Code.</td></tr><?php endif; ?>
<?php foreach ($events as $event): ?><tr>
<td><strong><?= e($event['name']) ?></strong><br><a href="<?= e(event_public_url($event)) ?>" target="_blank">Abrir página pública</a></td>
<td><?= e($event['location_name'] ?: '—') ?><br><span class="muted"><?= e($event['wifi_ssid'] ?: 'Wi-Fi não informado') ?></span></td>
<td><?= e(local_from_utc($event['start_at'],$event['timezone'],'d/m/Y H:i')) ?><br><span class="muted">até <?= e(local_from_utc($event['end_at'],$event['timezone'],'d/m/Y H:i')) ?></span></td>
<td><?= (int)$event['participants_count'] ?> pessoas<br><span class="muted"><?= (int)$event['subscriptions_count'] ?> aparelhos</span></td>
<td><span class="badge <?= e($event['status']) ?>"><?= e($event['status']) ?></span></td>
<td><a class="btn btn-secondary btn-small" href="<?= e(app_url('admin/event_form.php?id='.(int)$event['id'])) ?>">Editar</a></td>
</tr><?php endforeach; ?>
</tbody></table></div></section>
<?php admin_footer(); ?>
