<?php
declare(strict_types=1);

function admin_header(string $title, string $active = ''): void
{
    $config = app_config();
    $flashes = pull_flashes();
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2563eb">
<title><?= e($title) ?> — <?= e($config['app_name']) ?></title>
<link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="sidebar">
        <a class="sidebar-brand" href="<?= e(app_url('admin/index.php')) ?>">
            <span class="brand-mark small">AW</span>
            <span><strong><?= e($config['app_name']) ?></strong><small>Painel administrativo</small></span>
        </a>
        <nav>
            <?php
            $items = [
                'dashboard' => ['Dashboard', 'index.php'],
                'events' => ['Eventos', 'events.php'],
                'participants' => ['Participantes', 'participants.php'],
                'messages' => ['Mensagens', 'messages.php'],
                'settings' => ['Configurações', 'settings.php'],
            ];
            foreach ($items as $key => [$label, $file]): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= e(app_url('admin/' . $file)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-user">
            <strong><?= e($_SESSION['admin_name'] ?? '') ?></strong>
            <small><?= e($_SESSION['admin_email'] ?? '') ?></small>
            <a href="<?= e(app_url('admin/logout.php')) ?>">Sair</a>
        </div>
    </aside>
    <main class="admin-main">
        <header class="page-header">
            <div><p class="eyebrow">ALERTA WI-FI</p><h1><?= e($title) ?></h1></div>
        </header>
        <?php foreach ($flashes as $flash): ?>
            <div class="alert <?= $flash['type'] === 'error' ? 'alert-error' : 'alert-success' ?>"><?= e($flash['message']) ?></div>
        <?php endforeach; ?>
    <?php
}

function admin_footer(): void
{
    ?>
    </main>
</div>
<script src="<?= e(app_url('assets/js/admin.js')) ?>"></script>
</body>
</html>
    <?php
}
