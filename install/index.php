<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$lockFile = $root . '/storage/installed.lock';
$configFile = $root . '/config/config.php';
if (is_file($lockFile) && is_file($configFile)) {
    header('Location: ../admin/login.php');
    exit;
}

$requirements = [
    'PHP 8.1 ou superior' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Extensão PDO MySQL' => extension_loaded('pdo_mysql'),
    'Extensão OpenSSL' => extension_loaded('openssl'),
    'Extensão cURL' => extension_loaded('curl'),
    'Extensão JSON' => extension_loaded('json'),
    'Extensão Mbstring' => extension_loaded('mbstring'),
    'Função openssl_pkey_derive' => function_exists('openssl_pkey_derive'),
    'Pasta config gravável' => is_writable($root . '/config'),
    'Pasta storage gravável' => is_writable($root . '/storage'),
];

$error = null;
$success = null;
$cronToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        foreach ($requirements as $name => $ok) {
            if (!$ok) {
                throw new RuntimeException('O requisito "' . $name . '" não foi atendido.');
            }
        }

        $appName = trim($_POST['app_name'] ?? 'Alerta Wi-Fi');
        $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
        $timezone = trim($_POST['timezone'] ?? 'America/Sao_Paulo');
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = (int) ($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $adminName = trim($_POST['admin_name'] ?? '');
        $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $organizationName = trim($_POST['organization_name'] ?? $appName);
        $vapidSubject = trim($_POST['vapid_subject'] ?? '');

        if ($appName === '' || !filter_var($appUrl, FILTER_VALIDATE_URL) || !str_starts_with($appUrl, 'https://')) {
            throw new RuntimeException('Informe uma URL completa iniciada por https://.');
        }
        if ($dbName === '' || $dbUser === '') {
            throw new RuntimeException('Informe o nome e o usuário do banco de dados.');
        }
        if ($adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPassword) < 8) {
            throw new RuntimeException('Informe o administrador, um e-mail válido e uma senha com pelo menos 8 caracteres.');
        }
        if ($vapidSubject === '') {
            $vapidSubject = 'mailto:' . $adminEmail;
        }
        if (!str_starts_with($vapidSubject, 'mailto:') && !str_starts_with($vapidSubject, 'https://')) {
            throw new RuntimeException('O contato VAPID precisa começar com mailto: ou https://.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new RuntimeException('Fuso horário inválido.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
        $pdo->exec("SET time_zone = '+00:00'");

        $schema = file_get_contents($root . '/database.sql');
        if ($schema === false) {
            throw new RuntimeException('Arquivo database.sql não encontrado.');
        }
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }

        require_once $root . '/src/WebPushService.php';
        $keys = WebPushService::generateVapidKeys();
        $cronToken = bin2hex(random_bytes(24));
        $sessionSecret = bin2hex(random_bytes(32));

        $config = [
            'app_name' => $appName,
            'app_url' => $appUrl,
            'timezone' => $timezone,
            'db' => [
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => 'utf8mb4',
            ],
            'vapid' => [
                'subject' => $vapidSubject,
                'public_key' => $keys['public_key'],
                'private_key_pem' => $keys['private_key_pem'],
            ],
            'cron_token' => $cronToken,
            'session_secret' => $sessionSecret,
        ];

        $configContent = "<?php\n// Gerado automaticamente pelo instalador. Não exponha este arquivo.\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configFile, $configContent, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar config/config.php.');
        }

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO organizations (name, email, status, created_at) VALUES (?, ?, 'active', UTC_TIMESTAMP())")
            ->execute([$organizationName, $adminEmail]);
        $organizationId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO admin_users (organization_id, name, email, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, 'owner', 'active', UTC_TIMESTAMP())")
            ->execute([$organizationId, $adminName, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT)]);

        $start = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $end = $start->modify('+1 day');
        $pdo->prepare("INSERT INTO events (organization_id, name, slug, description, location_name, wifi_ssid, cover_color, start_at, end_at, timezone, registration_enabled, notification_enabled, status, created_at, updated_at) VALUES (?, 'Teste inicial', 'teste-inicial', 'Evento criado automaticamente para validar cadastro, autorização e notificações.', 'Local de teste', 'Informe o nome do seu Wi-Fi', '#2563eb', ?, ?, ?, 1, 1, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([
                $organizationId,
                $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $timezone,
            ]);
        $pdo->commit();

        file_put_contents($lockFile, 'Instalado em ' . gmdate('c') . "\n", LOCK_EX);
        file_put_contents($root . '/storage/migration-1.0.1.lock', 'Incluída na instalação em ' . gmdate('c') . "\n", LOCK_EX);
        $success = 'Instalação concluída. O banco, o administrador, as chaves VAPID e o evento de teste foram criados.';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($configFile) && !is_file($lockFile)) {
            @unlink($configFile);
        }
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalação — Alerta Wi-Fi</title>
<link rel="stylesheet" href="../assets/css/app.css">
</head>
<body class="install-page">
<main class="install-wrap">
    <section class="install-hero">
        <div class="brand-mark">AW</div>
        <p class="eyebrow">INSTALADOR AUTOMÁTICO</p>
        <h1>Alerta Wi-Fi</h1>
        <p>Cadastre participantes, obtenha autorização e programe notificações Web Push por evento.</p>
    </section>

    <section class="card install-card">
        <h2>1. Verificação do servidor</h2>
        <div class="requirements">
        <?php foreach ($requirements as $label => $ok): ?>
            <div class="requirement <?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✓' : '×' ?></span><?= htmlspecialchars($label) ?></div>
        <?php endforeach; ?>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <div class="next-steps">
                <h3>Próximos passos</h3>
                <p>Entre no painel e configure o nome do Wi-Fi no evento de teste.</p>
                <a class="btn btn-primary" href="../admin/login.php">Abrir painel administrativo</a>
                <p class="code-note">Configure um Cron Job a cada minuto apontando para:<br><code><?= htmlspecialchars(rtrim($_POST['app_url'] ?? '', '/')) ?>/cron_web.php?token=<?= htmlspecialchars((string) $cronToken) ?></code></p>
            </div>
        <?php else: ?>
        <form method="post" class="form-grid">
            <div class="section-title">Aplicação</div>
            <label>Nome do sistema<input name="app_name" required value="<?= htmlspecialchars($_POST['app_name'] ?? 'Alerta Wi-Fi') ?>"></label>
            <label>URL HTTPS do domínio<input name="app_url" type="url" required placeholder="https://alerta.seudominio.com.br" value="<?= htmlspecialchars($_POST['app_url'] ?? '') ?>"></label>
            <label>Nome da organização<input name="organization_name" required value="<?= htmlspecialchars($_POST['organization_name'] ?? 'Minha organização') ?>"></label>
            <label>Fuso horário<input name="timezone" required value="<?= htmlspecialchars($_POST['timezone'] ?? 'America/Sao_Paulo') ?>"></label>

            <div class="section-title">Banco de dados MySQL</div>
            <label>Servidor do banco<input name="db_host" required value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"></label>
            <label>Porta<input name="db_port" type="number" required value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>"></label>
            <label>Nome do banco<input name="db_name" required value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>"></label>
            <label>Usuário do banco<input name="db_user" required value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>"></label>
            <label class="span-2">Senha do banco<input name="db_pass" type="password" value=""></label>

            <div class="section-title">Administrador</div>
            <label>Nome completo<input name="admin_name" required value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>"></label>
            <label>E-mail<input name="admin_email" type="email" required value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>"></label>
            <label>Senha inicial<input name="admin_password" type="password" minlength="8" required></label>
            <label>Contato VAPID<input name="vapid_subject" placeholder="mailto:contato@dominio.com.br" value="<?= htmlspecialchars($_POST['vapid_subject'] ?? '') ?>"><small>Deixe vazio para usar o e-mail do administrador.</small></label>

            <button class="btn btn-primary span-2" type="submit">Instalar sistema e criar banco</button>
        </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
