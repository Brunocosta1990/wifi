<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
}

try {
    $data = request_json();
    $eventId = (int) ($data['event_id'] ?? 0);
    $token = trim((string) ($data['participant_token'] ?? ''));
    $endpoint = trim((string) ($data['endpoint'] ?? ''));
    if ($eventId < 1 || $token === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Identificação do aparelho incompleta.');
    }

    $payloads = QueueService::pull($eventId, $token, $endpoint);
    if ($payloads === null) {
        json_response(['success' => false, 'error' => 'Nenhuma mensagem pendente.'], 404);
    }
    json_response(['success' => true, 'payloads' => $payloads]);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 422);
}
