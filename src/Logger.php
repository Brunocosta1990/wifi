<?php
declare(strict_types=1);

final class Logger
{
    private const MAX_SIZE = 1048576;
    private const RETENTION = 7;
    private const CHANNELS = ['app','php','database','push','cron','service-worker','security'];

    public static function debug(string $message, array $context = [], string $channel = 'app'): void { self::log('debug', $message, $context, $channel); }
    public static function info(string $message, array $context = [], string $channel = 'app'): void { self::log('info', $message, $context, $channel); }
    public static function warning(string $message, array $context = [], string $channel = 'app'): void { self::log('warning', $message, $context, $channel); }
    public static function error(string $message, array $context = [], string $channel = 'app'): void { self::log('error', $message, $context, $channel); }
    public static function critical(string $message, array $context = [], string $channel = 'app'): void { self::log('critical', $message, $context, $channel); }
    public static function exception(Throwable $e, array $context = [], string $channel = 'php'): void
    {
        $context += ['exception_class' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()];
        self::log('error', $e->getMessage(), $context, $channel);
    }

    public static function correlationId(string $prefix = 'push'): string
    {
        return $prefix . '_' . gmdate('Ymd') . '_' . bin2hex(random_bytes(4));
    }

    public static function maskEndpoint(string $endpoint): string
    {
        if ($endpoint === '') return '';
        $parts = parse_url($endpoint);
        $host = $parts['host'] ?? 'endpoint';
        return $host . '/…' . substr(hash('sha256', $endpoint), 0, 12);
    }

    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        $channel = in_array($channel, self::CHANNELS, true) ? $channel : 'app';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? [];
        $record = [
            'created_at' => gmdate('c'), 'level' => $level, 'channel' => $channel, 'message' => $message,
            'request_id' => self::requestId(), 'correlation_id' => $context['correlation_id'] ?? null,
            'route' => $_SERVER['REQUEST_URI'] ?? ($context['route'] ?? 'cli'), 'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'file' => $context['file'] ?? ($trace['file'] ?? null), 'line' => $context['line'] ?? ($trace['line'] ?? null),
            'event_id' => $context['event_id'] ?? null, 'participant_id' => $context['participant_id'] ?? null,
            'notification_message_id' => $context['message_id'] ?? ($context['notification_message_id'] ?? null),
            'subscription_id' => $context['subscription_id'] ?? null, 'http_code' => $context['http_code'] ?? null,
            'context' => self::sanitize($context),
        ];
        self::writeFile($channel, $record);
        self::writeDatabase($record);
    }

    private static function requestId(): string
    {
        if (!isset($GLOBALS['ALERTA_WIFI_REQUEST_ID'])) $GLOBALS['ALERTA_WIFI_REQUEST_ID'] = 'req_' . bin2hex(random_bytes(8));
        return $GLOBALS['ALERTA_WIFI_REQUEST_ID'];
    }

    private static function writeFile(string $channel, array $record): void
    {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\nDeny from all\n");
        $file = $dir . '/' . $channel . '.log';
        if (is_file($file) && filesize($file) > self::MAX_SIZE) @rename($file, $file . '.' . gmdate('YmdHis'));
        foreach (glob($file . '.*') ?: [] as $old) if (filemtime($old) < time() - self::RETENTION * 86400) @unlink($old);
        @file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function writeDatabase(array $record): void
    {
        try {
            if (!class_exists('Database')) return;
            $pdo = Database::connection();
            $stmt = $pdo->prepare('INSERT INTO system_logs (level,channel,message,request_id,correlation_id,admin_user_id,event_id,participant_id,notification_message_id,subscription_id,route,http_method,http_code,context_json,ip_hash,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $stmt->execute([$record['level'],$record['channel'],$record['message'],$record['request_id'],$record['correlation_id'],$_SESSION['admin_id']??null,$record['event_id'],$record['participant_id'],$record['notification_message_id'],$record['subscription_id'],$record['route'],$record['http_method'],$record['http_code'],json_encode($record['context'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), hash('sha256', client_ip()), $_SERVER['HTTP_USER_AGENT'] ?? null]);
        } catch (Throwable) {}
    }

    private static function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = (string)$k;
                if (preg_match('/pass|password|private|csrf|cookie|authorization|cron_token|token/i', $key)) { $out[$k] = '[masked]'; continue; }
                if (preg_match('/endpoint/i', $key) && is_string($v)) { $out[$k] = self::maskEndpoint($v); continue; }
                $out[$k] = self::sanitize($v);
            }
            return $out;
        }
        if (is_string($value) && strlen($value) > 1000) return mb_substr($value, 0, 1000) . '…';
        return $value;
    }
}
