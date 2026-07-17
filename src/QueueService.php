<?php
declare(strict_types=1);

final class QueueService
{
    public static function enqueue(array $subscription, array $payload, ?int $messageId, int $ttl, ?string $correlationId = null): int
    {
        $ttl = max(60, min(86400, $ttl));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);
        $stmt = db()->prepare("INSERT INTO push_queue
            (subscription_id, participant_id, event_id, message_id, correlation_id, payload_json, status, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $stmt->execute([
            (int) $subscription['id'],
            (int) $subscription['participant_id'],
            (int) $subscription['event_id'],
            $messageId,
            $correlationId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $expiresAt,
        ]);
        return (int) db()->lastInsertId();
    }

    public static function markFailed(int $queueId): void
    {
        db()->prepare("UPDATE push_queue SET status='failed', updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'")
            ->execute([$queueId]);
    }

    /** @return array<int,array<string,mixed>>|null */
    public static function pull(int $eventId, string $participantToken, string $endpoint): ?array
    {
        $endpointHash = hash('sha256', $endpoint);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $subscriptionStmt = $pdo->prepare("SELECT ps.id, ps.participant_id, ps.event_id
                FROM push_subscriptions ps
                JOIN participants p ON p.id = ps.participant_id
                WHERE ps.event_id=? AND p.public_token=? AND ps.endpoint_hash=?
                  AND ps.status='active' AND p.status='active'
                LIMIT 1 FOR UPDATE");
            $subscriptionStmt->execute([$eventId, $participantToken, $endpointHash]);
            $subscription = $subscriptionStmt->fetch();
            if (!$subscription) {
                $pdo->rollBack();
                return null;
            }

            $queueStmt = $pdo->prepare("SELECT * FROM push_queue
                WHERE subscription_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP()
                ORDER BY id ASC LIMIT 20 FOR UPDATE");
            $queueStmt->execute([(int) $subscription['id']]);
            $rows = $queueStmt->fetchAll();
            if (!$rows) {
                $pdo->rollBack();
                return null;
            }

            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE push_queue SET status='consumed', consumed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id IN ({$placeholders})")
                ->execute($ids);
            $pdo->prepare("UPDATE push_subscriptions SET last_push_received_at=UTC_TIMESTAMP(), last_seen_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([(int) $subscription['id']]);

            $payloads = [];
            foreach ($rows as $row) {
                if (!empty($row['message_id'])) {
                    $pdo->prepare("UPDATE message_deliveries SET status='device_received', received_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE message_id=? AND subscription_id=?")
                        ->execute([(int) $row['message_id'], (int) $subscription['id']]);
                }
                $payload = json_decode((string) $row['payload_json'], true);
                if (is_array($payload)) {
                    $payload['correlation_id'] = $row['correlation_id'] ?? ($payload['correlation_id'] ?? null);
                    $payloads[] = $payload;
                    Logger::info('device_received', ['correlation_id' => $payload['correlation_id'] ?? null, 'message_id' => $row['message_id'] ?? null, 'subscription_id' => $subscription['id']], 'service-worker');
                }
            }

            $pdo->commit();
            return $payloads ?: null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
