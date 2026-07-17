<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf_token'] ?? null);
        if (($_POST['action'] ?? '') !== 'repair') {
            throw new RuntimeException('Ação inválida.');
        }
        $result = Migrator::run(true);
        if (!$result['success']) {
            throw new RuntimeException($result['message']);
        }
        flash('success', 'Banco reparado e atualizado para a versão 1.0.1.');
    } catch (Throwable $e) {
        flash('error', 'Não foi possível reparar o banco: ' . $e->getMessage());
    }
    redirect('admin/settings.php');
}

$config = app_config();
$cronUrl = app_url('cron_web.php?token=' . urlencode($config['cron_token']));

$dbInfo = ['database_name' => '—', 'charset_connection' => '—', 'collation_connection' => '—'];
$tableCollations = [];
$recentSubscriptions = [];
try {
    $dbInfo = db()->query("SELECT DATABASE() database_name, @@character_set_connection charset_connection, @@collation_connection collation_connection")->fetch() ?: $dbInfo;
    $stmt = db()->prepare("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    $stmt->execute();
    $tableCollations = $stmt->fetchAll();
    $recentSubscriptions = db()->query("SELECT ps.id, p.name participant_name, ps.browser, ps.platform, ps.status, ps.last_push_sent_at, ps.last_push_received_at, ps.last_push_http_status, ps.last_push_error
        FROM push_subscriptions ps JOIN participants p ON p.id=ps.participant_id ORDER BY ps.id DESC LIMIT 10")->fetchAll();
} catch (Throwable $e) {
    $dbInfo['error'] = $e->getMessage();
}

$logFile = dirname(__DIR__) . '/storage/logs/push.log';
$pushLog = '';
if (is_file($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $pushLog = implode("\n", array_slice($lines, -20));
}
$migrationError = '';
$migrationErrorFile = dirname(__DIR__) . '/storage/migration-error.log';
if (is_file($migrationErrorFile)) {
    $migrationError = (string) file_get_contents($migrationErrorFile);
}

admin_header('Configurações', 'settings');
?>
<div class="two-columns">
<section class="card panel">
    <h2>Agendamento automático</h2>
    <p>Configure um Cron Job para executar a cada minuto:</p>
    <div class="code-note"><code>curl -fsS "<?= e($cronUrl) ?>" &gt;/dev/null 2&gt;&amp;1</code></div>
    <p class="muted">Alternativa por PHP CLI:</p>
    <div class="code-note"><code>* * * * * php <?= e(dirname(__DIR__) . '/cron/process.php') ?> &gt;/dev/null 2&gt;&amp;1</code></div>
    <a class="btn btn-primary" target="_blank" href="<?= e($cronUrl) ?>">Testar agendador</a>
</section>

<section class="card panel">
    <h2>Diagnóstico geral</h2>
    <ul class="list-clean">
        <li><strong>Versão:</strong> 1.0.1</li>
        <li><strong>URL:</strong><br><?= e($config['app_url']) ?></li>
        <li><strong>PHP:</strong> <?= e(PHP_VERSION) ?></li>
        <li><strong>Banco:</strong> <?= e($dbInfo['database_name'] ?? '—') ?></li>
        <li><strong>Conexão:</strong> <?= e(($dbInfo['charset_connection'] ?? '—') . ' / ' . ($dbInfo['collation_connection'] ?? '—')) ?></li>
        <li><strong>Modo Push:</strong><br><span class="muted">Sinal Web Push + busca segura da mensagem no servidor.</span></li>
        <li><strong>Chave VAPID:</strong><br><span class="muted"><?= e(substr($config['vapid']['public_key'], 0, 32)) ?>…</span></li>
    </ul>
    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-secondary" name="action" value="repair" type="submit" data-confirm="Executar novamente o reparo das collations e da estrutura do banco?">Reparar banco e collations</button>
    </form>
</section>
</div>

<?php if ($migrationError): ?>
<section class="card panel"><h2>Erro de atualização</h2><div class="alert alert-error"><?= nl2br(e($migrationError)) ?></div></section>
<?php endif; ?>

<section class="card panel">
    <h2>Collation das tabelas</h2>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Tabela</th><th>Collation</th></tr></thead><tbody>
    <?php foreach ($tableCollations as $table): ?>
        <tr><td><?= e($table['TABLE_NAME']) ?></td><td><?= e($table['TABLE_COLLATION'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="card panel">
    <h2>Últimos aparelhos e Push</h2>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Participante</th><th>Aparelho</th><th>HTTP</th><th>Sinal enviado</th><th>Recebido pelo aparelho</th><th>Erro</th></tr></thead><tbody>
    <?php if (!$recentSubscriptions): ?><tr><td colspan="6" class="empty-state">Nenhum aparelho cadastrado.</td></tr><?php endif; ?>
    <?php foreach ($recentSubscriptions as $sub): ?>
        <tr>
            <td><?= e($sub['participant_name']) ?><br><span class="muted"><?= e($sub['status']) ?></span></td>
            <td><?= e($sub['platform'] ?: '—') ?><br><span class="muted"><?= e(mb_strimwidth((string) $sub['browser'], 0, 55, '…')) ?></span></td>
            <td><?= $sub['last_push_http_status'] !== null ? (int) $sub['last_push_http_status'] : '—' ?></td>
            <td><?= e(local_from_utc($sub['last_push_sent_at'], $config['timezone'])) ?></td>
            <td><?= e(local_from_utc($sub['last_push_received_at'], $config['timezone'])) ?></td>
            <td><span class="muted"><?= e($sub['last_push_error'] ?: '—') ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="card panel">
    <h2>Log recente do serviço Push</h2>
    <div class="code-note"><code><?= $pushLog !== '' ? nl2br(e($pushLog)) : 'Nenhum envio registrado ainda.' ?></code></div>
</section>
<?php admin_footer(); ?>
