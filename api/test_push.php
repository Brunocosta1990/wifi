<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
}

try {
    $data = request_json();
    $eventId = (int) ($data['event_id'] ?? 0);
    $token = (string) ($data['participant_token'] ?? '');
    $stmt = db()->prepare("SELECT ps.*, e.name event_name, e.slug
        FROM push_subscriptions ps
        JOIN participants p ON p.id=ps.participant_id
        JOIN events e ON e.id=ps.event_id
        WHERE ps.event_id=? AND p.public_token=? AND ps.status='active'
        ORDER BY ps.id DESC LIMIT 1");
    $stmt->execute([$eventId, $token]);
    $sub = $stmt->fetch();
    if (!$sub) {
        throw new RuntimeException('Nenhuma assinatura ativa foi encontrada neste aparelho.');
    }

    $sentAt = gmdate('c');
    $payload = [
        'title' => 'Teste recebido com sucesso',
        'body' => 'O sistema de notificações do ' . $sub['event_name'] . ' está funcionando.',
        'icon' => app_url('assets/icons/icon-192.png'),
        'badge' => app_url('assets/icons/badge-96.png'),
        'url' => app_url('e/' . $sub['slug']),
        'tag' => 'teste-aparelho-' . time(),
        'sent_at' => $sentAt,
    ];

    $queueId = QueueService::enqueue($sub, $payload, null, 300);
    $result = (new WebPushService())->sendSignal($sub, 300);

    db()->prepare("UPDATE push_subscriptions
        SET last_push_sent_at=UTC_TIMESTAMP(), last_push_http_status=?, last_push_error=?, updated_at=UTC_TIMESTAMP()
        WHERE id=?")
        ->execute([$result['status'], $result['error'], $sub['id']]);

    if (!$result['success']) {
        QueueService::markFailed($queueId);
        if ($result['expired']) {
            db()->prepare("UPDATE push_subscriptions SET status='expired', revoked_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([$sub['id']]);
        }
        throw new RuntimeException($result['error'] ?: 'Falha ao enviar o sinal de teste.');
    }

    json_response([
        'success' => true,
        'http_status' => $result['status'],
        'sent_at' => $sentAt,
        'subscription_id' => (int) $sub['id'],
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 422);
}
