<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'error'=>'Método não permitido.'],405);
$data = request_json();
$event = preg_replace('/[^a-z0-9_\-]/i', '', (string)($data['event'] ?? 'client_event')) ?: 'client_event';
$correlationId = $data['correlation_id'] ?? null;
$messageId = isset($data['message_id']) ? (int)$data['message_id'] : null;
Logger::info($event, [
    'correlation_id' => $correlationId,
    'event_id' => isset($data['event_id']) ? (int)$data['event_id'] : null,
    'participant_id' => isset($data['participant_id']) ? (int)$data['participant_id'] : null,
    'message_id' => $messageId,
    'subscription_id' => isset($data['subscription_id']) ? (int)$data['subscription_id'] : null,
    'client_context' => $data,
], $event === 'service_worker_error' || str_starts_with($event, 'push_') || str_starts_with($event, 'notification_') ? 'service-worker' : 'app');
try {
    if ($correlationId && $event === 'notification_show_success') {
        db()->prepare("UPDATE message_deliveries SET status='notification_displayed', displayed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE correlation_id=? AND status IN ('queued','provider_accepted','device_received','submitted')")->execute([$correlationId]);
    }
    if ($correlationId && $event === 'notification_clicked') {
        db()->prepare("UPDATE message_deliveries SET status='clicked', clicked_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE correlation_id=?")->execute([$correlationId]);
    }
} catch (Throwable $e) { Logger::exception($e, ['correlation_id' => $correlationId], 'service-worker'); }
json_response(['success'=>true]);
