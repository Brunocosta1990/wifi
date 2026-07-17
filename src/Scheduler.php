<?php
declare(strict_types=1);

final class Scheduler
{
    public static function run(int $limit = 10): array
    {
        $lockPath = dirname(__DIR__) . '/storage/cron.lock';
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            Logger::warning('cron_lock_busy', [], 'cron');
            return ['processed' => 0, 'success' => 0, 'failed' => 0, 'message' => 'Outro processamento já está em execução.'];
        }

        $started = microtime(true);
        $summary = ['processed' => 0, 'success' => 0, 'failed' => 0, 'expired' => 0, 'message' => 'OK'];
        Logger::info('cron_started', ['limit' => $limit], 'cron');
        self::heartbeat('started', $summary);
        try {
            // Recupera mensagens presas após uma interrupção do processo.
            db()->exec("UPDATE messages SET status='scheduled', updated_at=UTC_TIMESTAMP(), last_error='Reprocessamento automático após interrupção.' WHERE status='processing' AND sent_at IS NULL AND updated_at < (UTC_TIMESTAMP() - INTERVAL 10 MINUTE)");

            $stmt = db()->prepare("SELECT id, scheduled_at, TIMESTAMPDIFF(SECOND, scheduled_at, UTC_TIMESTAMP()) AS schedule_delay_seconds FROM messages WHERE status = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY scheduled_at ASC LIMIT " . max(1, min(100, $limit)));
            $stmt->execute();
            $dueMessages = $stmt->fetchAll();
            Logger::info('cron_due_messages_found', ['count' => count($dueMessages), 'messages' => array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'scheduled_at' => $row['scheduled_at'], 'delay_seconds' => (int)$row['schedule_delay_seconds']], $dueMessages)], 'cron');

            foreach ($dueMessages as $dueMessage) {
                $messageId = (int) $dueMessage['id'];
                $claim = db()->prepare("UPDATE messages SET status = 'processing', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'scheduled'");
                $claim->execute([$messageId]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $result = self::processMessage($messageId);
                $summary['max_schedule_delay_seconds'] = max((int)($summary['max_schedule_delay_seconds'] ?? 0), (int)$dueMessage['schedule_delay_seconds']);
                $summary['processed']++;
                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
            }

            db()->exec("UPDATE messages SET status='failed', last_error='A mensagem expirou antes do processamento.', updated_at=UTC_TIMESTAMP() WHERE status='scheduled' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()");
            db()->exec("UPDATE push_queue SET status='expired', updated_at=UTC_TIMESTAMP() WHERE status='pending' AND expires_at <= UTC_TIMESTAMP()");
        } catch (Throwable $e) {
            $summary['message'] = $e->getMessage();
            Logger::exception($e, ['summary' => $summary], 'cron');
            self::heartbeat('error', $summary, $e->getMessage(), (int)((microtime(true)-$started)*1000));
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            $summary['duration_ms'] = (int)((microtime(true)-$started)*1000);
            Logger::info('cron_finished', $summary, 'cron');
            self::heartbeat($summary['message'] === 'OK' ? 'success' : 'finished', $summary, $summary['message'] === 'OK' ? null : $summary['message'], $summary['duration_ms']);
        }

        return $summary;
    }

    public static function processMessage(int $messageId): array
    {
        $stmt = db()->prepare('SELECT m.*, e.slug, e.name AS event_name FROM messages m JOIN events e ON e.id=m.event_id WHERE m.id=? LIMIT 1');
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        if ($message) {
            Logger::info('message_processing_started', ['message_id' => $messageId, 'event_id' => (int)$message['event_id'], 'scheduled_at' => $message['scheduled_at'], 'schedule_delay_seconds' => max(0, time() - strtotime($message['scheduled_at'] . ' UTC'))], 'cron');
        }
        if (!$message) {
            return ['success' => 0, 'failed' => 1];
        }

        if ($message['status'] === 'scheduled') {
            $claim = db()->prepare("UPDATE messages SET status='processing', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='scheduled'");
            $claim->execute([$messageId]);
            if ($claim->rowCount() !== 1) {
                return ['success' => 0, 'failed' => 0];
            }
            $message['status'] = 'processing';
        } elseif ($message['status'] !== 'processing') {
            return ['success' => 0, 'failed' => 0];
        }

        $sql = "SELECT ps.* FROM push_subscriptions ps JOIN participants p ON p.id=ps.participant_id WHERE ps.event_id=? AND ps.status='active' AND p.status='active'";
        $params = [$message['event_id']];
        if ($message['audience_type'] === 'individual' && $message['participant_id']) {
            $sql .= ' AND ps.participant_id=?';
            $params[] = $message['participant_id'];
        }
        $sql .= ' ORDER BY ps.id ASC';
        $subscriptions = db()->prepare($sql);
        $subscriptions->execute($params);
        $targets = $subscriptions->fetchAll();

        db()->prepare('UPDATE messages SET total_targets=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([count($targets), $messageId]);

        $push = new WebPushService();
        $success = 0;
        $failed = 0;
        $lastError = null;
        $actionUrl = trim((string) $message['action_url']);
        if ($actionUrl === '') {
            $actionUrl = app_url('e/' . $message['slug']);
        } elseif (!preg_match('#^https?://#i', $actionUrl)) {
            $actionUrl = app_url($actionUrl);
        }

        $correlationId = $message['correlation_id'] ?: Logger::correlationId();
        db()->prepare("UPDATE messages SET correlation_id=? WHERE id=? AND (correlation_id IS NULL OR correlation_id='')")->execute([$correlationId, $messageId]);

        $payload = [
            'message_id' => (int) $messageId,
            'correlation_id' => $correlationId,
            'title' => $message['title'],
            'body' => $message['body'],
            'icon' => $message['icon_url'] ?: app_url('assets/icons/icon-192.png'),
            'badge' => app_url('assets/icons/badge-96.png'),
            'image' => $message['image_url'] ?: null,
            'url' => $actionUrl,
            'tag' => $message['tag'] ?: ('message-' . $messageId),
            'sent_at' => gmdate('c'),
        ];

        foreach ($targets as $subscription) {
            $insert = db()->prepare("INSERT INTO message_deliveries (message_id, subscription_id, participant_id, correlation_id, status, attempts, created_at, updated_at) VALUES (?, ?, ?, ?, 'queued', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE updated_at=UTC_TIMESTAMP()");
            $insert->execute([$messageId, $subscription['id'], $subscription['participant_id'], $correlationId]);

            $ttl = self::ttlForMessage($message);
            $queueId = QueueService::enqueue($subscription, $payload, $messageId, max(60, $ttl), $correlationId);
            $result = $push->sendSignal($subscription, $ttl, ['correlation_id' => $correlationId, 'message_id' => $messageId, 'subscription_id' => (int)$subscription['id'], 'attempt' => 1]);
            $deliveryStatus = $result['success'] ? 'provider_accepted' : ($result['expired'] ? 'expired' : 'provider_rejected');
            $submittedFlag = $result['success'] ? 1 : 0;

            // Usa flag numérica, evitando comparação textual com collations diferentes.
            db()->prepare("UPDATE message_deliveries SET status=?, response_code=?, attempts=attempts+1, error_message=?, submitted_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE submitted_at END, updated_at=UTC_TIMESTAMP() WHERE message_id=? AND subscription_id=?")
                ->execute([$deliveryStatus, $result['status'], $result['error'], $submittedFlag, $messageId, $subscription['id']]);

            db()->prepare("UPDATE push_subscriptions SET last_push_sent_at=UTC_TIMESTAMP(), last_push_http_status=?, last_push_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([$result['status'], $result['error'], $subscription['id']]);

            if ($result['success']) {
                $success++;
            } else {
                QueueService::markFailed($queueId);
                $failed++;
                $lastError = $result['error'];
                if ($result['expired']) {
                    db()->prepare("UPDATE push_subscriptions SET status='expired', updated_at=UTC_TIMESTAMP(), revoked_at=UTC_TIMESTAMP() WHERE id=?")
                        ->execute([$subscription['id']]);
                }
            }
        }

        $finalStatus = ($failed > 0 && $success === 0 && count($targets) > 0) ? 'failed' : 'sent';
        db()->prepare('UPDATE messages SET status=?, sent_at=UTC_TIMESTAMP(), total_success=?, total_failed=?, last_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$finalStatus, $success, $failed, $lastError, $messageId]);

        Logger::info('message_processing_finished', ['message_id' => $messageId, 'event_id' => (int)$message['event_id'], 'success' => $success, 'failed' => $failed, 'targets' => count($targets)], 'cron');
        return ['success' => $success, 'failed' => $failed];
    }

    private static function heartbeat(string $state, array $summary, ?string $error = null, ?int $durationMs = null): void
    {
        try {
            db()->prepare("INSERT INTO cron_heartbeat (id,last_started_at,last_finished_at,last_success_at,last_error,last_duration_ms,last_summary_json,updated_at) VALUES (1,CASE WHEN ?='started' THEN UTC_TIMESTAMP() ELSE NULL END,CASE WHEN ?<>'started' THEN UTC_TIMESTAMP() ELSE NULL END,CASE WHEN ?='success' THEN UTC_TIMESTAMP() ELSE NULL END,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_started_at=IF(?='started',UTC_TIMESTAMP(),last_started_at), last_finished_at=IF(?<>'started',UTC_TIMESTAMP(),last_finished_at), last_success_at=IF(?='success',UTC_TIMESTAMP(),last_success_at), last_error=VALUES(last_error), last_duration_ms=VALUES(last_duration_ms), last_summary_json=VALUES(last_summary_json), updated_at=UTC_TIMESTAMP()")->execute([$state,$state,$state,$error,$durationMs,json_encode($summary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$state,$state,$state]);
        } catch (Throwable $e) { Logger::exception($e, [], 'cron'); }
    }

    private static function ttlForMessage(array $message): int
    {
        if (empty($message['expires_at'])) {
            return 3600;
        }
        $expires = new DateTimeImmutable($message['expires_at'], new DateTimeZone('UTC'));
        return max(0, min(86400, $expires->getTimestamp() - time()));
    }
}
