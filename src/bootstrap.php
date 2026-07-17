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
require_once $root . '/src/Logger.php';
require_once $root . '/src/Database.php';
require_once $root . '/src/Auth.php';
require_once $root . '/src/WebPushService.php';
require_once $root . '/src/QueueService.php';
require_once $root . '/src/Scheduler.php';
require_once $root . '/src/Migrator.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    Logger::error($message, ['file' => $file, 'line' => $line, 'severity' => $severity], 'php');
    return false;
});
set_exception_handler(static function (Throwable $e): void {
    Logger::exception($e);
    if (PHP_SAPI !== 'cli' && !headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    echo PHP_SAPI === 'cli' ? ('Erro: ' . $e->getMessage() . PHP_EOL) : 'Ocorreu um erro inesperado. Informe o suporte e envie os logs com o request_id.';
});
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
        Logger::critical($error['message'], ['file' => $error['file'], 'line' => $error['line'], 'severity' => $error['type']], 'php');
    }
});

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
