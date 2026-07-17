<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (!str_contains($script, '/install/')) {
        header('Location: install/');
        exit;
    }
    return;
}

require_once $root . '/src/helpers.php';
require_once $root . '/src/Database.php';
require_once $root . '/src/Auth.php';
require_once $root . '/src/WebPushService.php';
require_once $root . '/src/QueueService.php';
require_once $root . '/src/Scheduler.php';
require_once $root . '/src/Migrator.php';

$config = app_config();
date_default_timezone_set($config['timezone'] ?? 'America/Sao_Paulo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('alertawifi_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Atualização automática e idempotente para instalações existentes.
$migrationResult = Migrator::run(false);
if (!$migrationResult['success']) {
    error_log('Migration 1.0.1: ' . $migrationResult['message']);
}
