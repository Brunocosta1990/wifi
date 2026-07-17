<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();
$orgId = (int) $_SESSION['organization_id'];

$statsStmt = db()->prepare("SELECT
 (SELECT COUNT(*) FROM events WHERE organization_id=?) AS events_count,
 (SELECT COUNT(*) FROM participants p JOIN events e ON e.id=p.event_id WHERE e.organization_id=? AND p.status='active') AS participants_count,
 (SELECT COUNT(*) FROM push_subscriptions ps JOIN events e ON e.id=ps.event_id WHERE e.organization_id=? AND ps.status='active') AS subscriptions_count,
 (SELECT COUNT(*) FROM messages m JOIN events e ON e.id=m.event_id WHERE e.organization_id=? AND m.status='scheduled') AS scheduled_count");
$statsStmt->execute([$orgId,$orgId,$orgId,$orgId]);
$stats = $statsStmt->fetch();

$messagesStmt = db()->prepare("SELECT m.*, e.name event_name FROM messages m JOIN events e ON e.id=m.event_id WHERE e.organization_id=? ORDER BY m.created_at DESC LIMIT 8");
$messagesStmt->execute([$orgId]);
$messages = $messagesStmt->fetchAll();

$eventsStmt = db()->prepare("SELECT e.*,
 (SELECT COUNT(*) FROM participants p WHERE p.event_id=e.id AND p.status='active') participants_count,
 (SELECT COUNT(*) FROM push_subscriptions ps WHERE ps.event_id=e.id AND ps.status='active') subscriptions_count
 FROM events e WHERE e.organization_id=? ORDER BY e.created_at DESC LIMIT 5");
$eventsStmt->execute([$orgId]);
$events = $eventsStmt->fetchAll();

admin_header('Dashboard', 'dashboard');
?>
<div class="toolbar"><a class="btn btn-primary" href="<?= e(app_url('admin/message_form.php')) ?>">Nova mensagem</a><a class="btn btn-secondary" href="<?= e(app_url('admin/event_form.php')) ?>">Novo evento</a></div>
<section class="stats-grid">
    <div class="card stat-card"><small>Eventos</small><strong><?= (int)$stats['events_count'] ?></strong></div>
    <div class="card stat-card"><small>Participantes</small><strong><?= (int)$stats['participants_count'] ?></strong></div>
    <div class="card stat-card"><small>Aparelhos autorizados</small><strong><?= (int)$stats['subscriptions_count'] ?></strong></div>
    <div class="card stat-card"><small>Mensagens programadas</small><strong><?= (int)$stats['scheduled_count'] ?></strong></div>
</section>
<div class="content-grid">
    <section class="card panel">
        <h2>Mensagens recentes</h2>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Mensagem</th><th>Evento</th><th>Horário</th><th>Status</th><th>Resultado</th></tr></thead><tbody>
        <?php if (!$messages): ?><tr><td colspan="5" class="empty-state">Nenhuma mensagem criada.</td></tr><?php endif; ?>
        <?php foreach ($messages as $message): ?><tr>
            <td><strong><?= e($message['title']) ?></strong><br><span class="muted"><?= e(mb_strimwidth($message['body'],0,65,'…')) ?></span></td>
            <td><?= e($message['event_name']) ?></td>
            <td><?= e(local_from_utc($message['scheduled_at'], $message['timezone'])) ?></td>
            <td><span class="badge <?= e($message['status']) ?>"><?= e($message['status']) ?></span></td>
            <td><?= (int)$message['total_success'] ?> ok / <?= (int)$message['total_failed'] ?> falhas</td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="card panel">
        <h2>Eventos</h2>
        <ul class="list-clean">
        <?php if (!$events): ?><li class="muted">Nenhum evento criado.</li><?php endif; ?>
        <?php foreach ($events as $event): ?><li>
            <strong><?= e($event['name']) ?></strong><br><small class="muted"><?= (int)$event['participants_count'] ?> cadastrados · <?= (int)$event['subscriptions_count'] ?> aparelhos</small><br>
            <a href="<?= e(app_url('admin/event_form.php?id='.(int)$event['id'])) ?>">Abrir evento</a>
        </li><?php endforeach; ?>
        </ul>
    </section>
</div>
<?php admin_footer(); ?>
