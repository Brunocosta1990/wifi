<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success'=>false,'error'=>'Método não permitido.'],405);
$data = request_json();
$event = preg_replace('/[^a-z0-9_\-]/i', '', (string)($data['event'] ?? 'client_event')) ?: 'client_event';
Logger::info($event, [
    'correlation_id' => $data['correlation_id'] ?? null,
    'event_id' => isset($data['event_id']) ? (int)$data['event_id'] : null,
    'participant_id' => isset($data['participant_id']) ? (int)$data['participant_id'] : null,
    'message_id' => isset($data['message_id']) ? (int)$data['message_id'] : null,
    'subscription_id' => isset($data['subscription_id']) ? (int)$data['subscription_id'] : null,
    'client_context' => $data,
], $event === 'service_worker_error' || str_starts_with($event, 'push_') || str_starts_with($event, 'notification_') ? 'service-worker' : 'app');
json_response(['success'=>true]);
