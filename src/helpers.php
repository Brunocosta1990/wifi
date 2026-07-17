<?php
declare(strict_types=1);

function app_config(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }
    $file = dirname(__DIR__) . '/config/config.php';
    if (!is_file($file)) {
        throw new RuntimeException('Sistema não instalado.');
    }
    $config = require $file;
    return $config;
}

function db(): PDO
{
    return Database::connection();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) app_config()['app_url'], '/');
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function redirect(string $path): never
{
    $location = preg_match('#^https?://#i', $path) ? $path : app_url($path);
    header('Location: ' . $location);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }
        $ip = trim(explode(',', $candidate)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = random_token(32);
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(?string $token): void
{
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function utc_from_local(string $localDateTime, string $timezone = 'America/Sao_Paulo'): string
{
    $date = new DateTimeImmutable($localDateTime, new DateTimeZone($timezone));
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function local_from_utc(?string $utcDateTime, string $timezone = 'America/Sao_Paulo', string $format = 'd/m/Y H:i'): string
{
    if (!$utcDateTime) {
        return '—';
    }
    $date = new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone($timezone))->format($format);
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'evento-' . date('YmdHis');
}

function audit(string $action, string $entityType, ?int $entityId = null, array $data = []): void
{
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (admin_user_id, action, entity_type, entity_id, data_json, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([
            $_SESSION['admin_id'] ?? null,
            $action,
            $entityType,
            $entityId,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            client_ip(),
        ]);
    } catch (Throwable $e) {
        error_log('Audit error: ' . $e->getMessage());
    }
}

function event_public_url(array $event): string
{
    return app_url('e/' . $event['slug']);
}

function active_event_by_slug(string $slug): ?array
{
    $stmt = db()->prepare("SELECT * FROM events WHERE slug = ? AND status IN ('active','draft') LIMIT 1");
    $stmt->execute([$slug]);
    $event = $stmt->fetch();
    return $event ?: null;
}
