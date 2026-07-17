<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (Auth::check()) {
    redirect('admin/index.php');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf_token'] ?? null);
        if (!Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            throw new RuntimeException('E-mail ou senha inválidos.');
        }
        audit('login', 'admin_users', (int) $_SESSION['admin_id']);
        redirect('admin/index.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entrar — <?= e(app_config()['app_name']) ?></title>
<link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head>
<body class="login-page">
<section class="card login-card">
    <div class="login-brand"><span class="brand-mark small">AW</span><div><strong><?= e(app_config()['app_name']) ?></strong><div class="muted">Painel administrativo</div></div></div>
    <h1>Bem-vindo</h1><p class="muted">Acesse para criar eventos e programar notificações.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>E-mail<input name="email" type="email" required autocomplete="email"></label>
        <label>Senha<input name="password" type="password" required autocomplete="current-password"></label>
        <button class="btn btn-primary" type="submit">Entrar</button>
    </form>
</section>
</body></html>
