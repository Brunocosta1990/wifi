<?php
declare(strict_types=1);

final class Scheduler
{
    public static function run(int $limit = 10): array
    {
        $lockPath = dirname(__DIR__) . '/storage/cron.lock';
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0, 'message' => 'Outro processamento já está em execução.'];
        }

        $summary = ['processed' => 0, 'success' => 0, 'failed' => 0, 'message' => 'OK'];
        try {
            // Recupera mensagens presas após uma interrupção do processo.
            db()->exec("UPDATE messages SET status='scheduled', updated_at=UTC_TIMESTAMP(), last_error='Reprocessamento automático após interrupção.' WHERE status='processing' AND sent_at IS NULL AND updated_at < (UTC_TIMESTAMP() - INTERVAL 10 MINUTE)");

            $stmt = db()->prepare("SELECT id FROM messages WHERE status = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP() AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) ORDER BY scheduled_at ASC LIMIT " . max(1, min(100, $limit)));
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));

            foreach ($ids as $messageId) {
                $claim = db()->prepare("UPDATE messages SET status = 'processing', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'scheduled'");
                $claim->execute([$messageId]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $result = self::processMessage($messageId);
                $summary['processed']++;
                $summary['success'] += $result['success'];
                $summary['failed'] += $result['failed'];
            }

            db()->exec("UPDATE messages SET status='failed', last_error='A mensagem expirou antes do processamento.', updated_at=UTC_TIMESTAMP() WHERE status='scheduled' AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()");
            db()->exec("UPDATE push_queue SET status='expired', updated_at=UTC_TIMESTAMP() WHERE status='pending' AND expires_at <= UTC_TIMESTAMP()");
        } catch (Throwable $e) {
            $summary['message'] = $e->getMessage();
            error_log('Scheduler error: ' . $e->getMessage());
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return $summary;
    }

    public static function processMessage(int $messageId): array
    {
        $stmt = db()->prepare('SELECT m.*, e.slug, e.name AS event_name FROM messages m JOIN events e ON e.id=m.event_id WHERE m.id=? LIMIT 1');
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
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

        $payload = [
            'message_id' => (int) $messageId,
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
            $insert = db()->prepare("INSERT INTO message_deliveries (message_id, subscription_id, participant_id, status, attempts, created_at, updated_at) VALUES (?, ?, ?, 'queued', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE updated_at=UTC_TIMESTAMP()");
            $insert->execute([$messageId, $subscription['id'], $subscription['participant_id']]);

            $ttl = self::ttlForMessage($message);
            $queueId = QueueService::enqueue($subscription, $payload, $messageId, max(60, $ttl));
            $result = $push->sendSignal($subscription, $ttl);
            $deliveryStatus = $result['success'] ? 'submitted' : ($result['expired'] ? 'expired' : 'failed');
            $submittedFlag = $deliveryStatus === 'submitted' ? 1 : 0;

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

        return ['success' => $success, 'failed' => $failed];
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
