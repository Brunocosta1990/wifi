<?php
declare(strict_types=1);

final class Migrator
{
    private const VERSION = '1.0.2';

    /** @return array{success:bool,message:string,steps:array<int,string>} */
    public static function run(bool $force = false): array
    {
        $root = dirname(__DIR__);
        $lockFile = $root . '/storage/migration-' . self::VERSION . '.lock';
        if (!$force && is_file($lockFile)) {
            return ['success' => true, 'message' => 'Atualização já aplicada.', 'steps' => []];
        }

        $pdo = Database::connection();
        $steps = [];
        $tables = [
            'organizations', 'admin_users', 'events', 'participants', 'consent_logs',
            'push_subscriptions', 'messages', 'message_deliveries', 'audit_logs', 'push_queue', 'system_logs', 'cron_heartbeat',
        ];

        try {
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
            $steps[] = 'Conexão MySQL ajustada para utf8mb4_unicode_ci.';

            foreach ($tables as $table) {
                if (self::tableExists($pdo, $table)) {
                    $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $steps[] = "Collation corrigida em {$table}.";
                }
            }

            self::addColumnIfMissing($pdo, 'push_subscriptions', 'last_push_sent_at', 'DATETIME NULL AFTER revoked_at');
            self::addColumnIfMissing($pdo, 'push_subscriptions', 'last_push_received_at', 'DATETIME NULL AFTER last_push_sent_at');
            self::addColumnIfMissing($pdo, 'push_subscriptions', 'last_push_http_status', 'INT NULL AFTER last_push_received_at');
            self::addColumnIfMissing($pdo, 'push_subscriptions', 'last_push_error', 'TEXT NULL AFTER last_push_http_status');
            self::addColumnIfMissing($pdo, 'message_deliveries', 'received_at', 'DATETIME NULL AFTER submitted_at');
            self::addColumnIfMissing($pdo, 'messages', 'correlation_id', 'VARCHAR(100) NULL AFTER id');
            self::addColumnIfMissing($pdo, 'message_deliveries', 'correlation_id', 'VARCHAR(100) NULL AFTER id');
            self::addColumnIfMissing($pdo, 'message_deliveries', 'displayed_at', 'DATETIME NULL AFTER received_at');
            self::addColumnIfMissing($pdo, 'push_queue', 'correlation_id', 'VARCHAR(100) NULL AFTER id');
            if (self::tableExists($pdo, 'message_deliveries')) {
                $pdo->exec("ALTER TABLE message_deliveries MODIFY status ENUM('queued','processing','submitted','provider_accepted','provider_rejected','device_received','notification_displayed','clicked','failed','expired') NOT NULL DEFAULT 'queued'");
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS push_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subscription_id BIGINT UNSIGNED NOT NULL,
                participant_id BIGINT UNSIGNED NOT NULL,
                event_id BIGINT UNSIGNED NOT NULL,
                message_id BIGINT UNSIGNED NULL,
                payload_json JSON NOT NULL,
                status ENUM('pending','consumed','expired','failed') NOT NULL DEFAULT 'pending',
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_queue_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
                CONSTRAINT fk_queue_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
                CONSTRAINT fk_queue_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
                CONSTRAINT fk_queue_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
                INDEX idx_queue_pull (subscription_id, status, expires_at, id),
                INDEX idx_queue_message (message_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $steps[] = 'Fila segura de mensagens criada.';


            $pdo->exec("CREATE TABLE IF NOT EXISTS system_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level VARCHAR(20) NOT NULL,
                channel VARCHAR(50) NOT NULL,
                message TEXT NOT NULL,
                request_id VARCHAR(100) NULL,
                correlation_id VARCHAR(100) NULL,
                admin_user_id BIGINT UNSIGNED NULL,
                event_id BIGINT UNSIGNED NULL,
                participant_id BIGINT UNSIGNED NULL,
                notification_message_id BIGINT UNSIGNED NULL,
                subscription_id BIGINT UNSIGNED NULL,
                route VARCHAR(255) NULL,
                http_method VARCHAR(10) NULL,
                http_code INT NULL,
                context_json LONGTEXT NULL,
                ip_hash VARCHAR(100) NULL,
                user_agent TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_logs_created_at (created_at),
                INDEX idx_logs_level (level),
                INDEX idx_logs_channel (channel),
                INDEX idx_logs_correlation (correlation_id)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cron_heartbeat (
                id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
                last_started_at DATETIME NULL,
                last_finished_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error TEXT NULL,
                last_duration_ms INT UNSIGNED NULL,
                last_summary_json LONGTEXT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $steps[] = 'Tabelas system_logs e cron_heartbeat criadas.';

            // Recupera mensagens que ficaram presas após o erro da versão anterior.
            $pdo->exec("UPDATE messages SET status='scheduled', last_error='Reprocessada após atualização 1.0.2.', updated_at=UTC_TIMESTAMP() WHERE status='processing' AND sent_at IS NULL");
            $pdo->exec("UPDATE push_queue SET status='expired', updated_at=UTC_TIMESTAMP() WHERE status='pending' AND expires_at <= UTC_TIMESTAMP()");

            file_put_contents($lockFile, 'Aplicada em ' . gmdate('c') . "\n", LOCK_EX);
            @unlink($root . '/storage/migration-error.log');
            return ['success' => true, 'message' => 'Atualização 1.0.2 aplicada com sucesso.', 'steps' => $steps];
        } catch (Throwable $e) {
            Logger::exception($e, ['migration_version' => self::VERSION], 'database');
            file_put_contents(
                $root . '/storage/migration-error.log',
                '[' . gmdate('c') . '] ' . $e->getMessage() . "\n",
                FILE_APPEND | LOCK_EX
            );
            return ['success' => false, 'message' => $e->getMessage(), 'steps' => $steps];
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}
