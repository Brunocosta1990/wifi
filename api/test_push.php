<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);

try {
    $data = request_json();
    $eventId = (int) ($data['event_id'] ?? 0);
    $token = (string) ($data['participant_token'] ?? '');
    $endpoint = trim((string)($data['endpoint'] ?? ''));
    $correlationId = Logger::correlationId('push');
    $steps = [];

    $participantStmt = db()->prepare('SELECT p.*, e.name event_name, e.slug FROM participants p JOIN events e ON e.id=p.event_id WHERE p.event_id=? AND p.public_token=? AND p.status=\'active\' LIMIT 1');
    $participantStmt->execute([$eventId, $token]);
    $participant = $participantStmt->fetch();
    if (!$participant) throw new RuntimeException('Etapa 1 falhou: participante não localizado.');
    $steps[] = ['step'=>1,'status'=>'ok','message'=>'Participante localizado'];

    $sql = "SELECT ps.*, ? event_name, ? slug FROM push_subscriptions ps WHERE ps.event_id=? AND ps.participant_id=? AND ps.status='active'";
    $params = [$participant['event_name'], $participant['slug'], $eventId, $participant['id']];
    if ($endpoint !== '') { $sql .= ' AND ps.endpoint_hash=?'; $params[] = hash('sha256', $endpoint); }
    $sql .= ' ORDER BY ps.id DESC LIMIT 1';
    $stmt = db()->prepare($sql); $stmt->execute($params); $sub = $stmt->fetch();
    if (!$sub) throw new RuntimeException('Etapa 2 falhou: assinatura ativa deste aparelho não localizada.');
    $steps[] = ['step'=>2,'status'=>'ok','message'=>'Assinatura localizada'];
    if (($sub['permission_status'] ?? '') !== 'granted') throw new RuntimeException('Etapa 3 falhou: permissão não autorizada.');
    $steps[] = ['step'=>3,'status'=>'ok','message'=>'Permissão autorizada'];
    if (!filter_var((string)$sub['endpoint'], FILTER_VALIDATE_URL)) throw new RuntimeException('Etapa 4 falhou: endpoint inválido.');
    $steps[] = ['step'=>4,'status'=>'ok','message'=>'Endpoint validado'];
    if (empty(app_config()['vapid']['public_key']) || empty(app_config()['vapid']['private_key_pem'])) throw new RuntimeException('Etapa 5 falhou: chaves VAPID ausentes.');
    $steps[] = ['step'=>5,'status'=>'ok','message'=>'Chaves VAPID validadas'];

    $sentAt = gmdate('c');
    $payload = ['title'=>'Teste recebido com sucesso','body'=>'O sistema de notificações do '.$sub['event_name'].' está funcionando.','icon'=>app_url('assets/icons/icon-192.png'),'badge'=>app_url('assets/icons/badge-96.png'),'url'=>app_url('e/'.$sub['slug']),'tag'=>'teste-aparelho-'.time(),'sent_at'=>$sentAt,'correlation_id'=>$correlationId];
    $queueId = QueueService::enqueue($sub, $payload, null, 300, $correlationId);
    $result = (new WebPushService())->sendSignal($sub, 300, ['correlation_id'=>$correlationId,'subscription_id'=>(int)$sub['id'],'attempt'=>1]);
    $steps[] = ['step'=>6,'status'=>'ok','message'=>'Requisição enviada'];
    $steps[] = ['step'=>7,'status'=>$result['status']?'ok':'error','message'=>'Código HTTP recebido','http_status'=>$result['status']];

    db()->prepare('UPDATE push_subscriptions SET last_push_sent_at=UTC_TIMESTAMP(), last_push_http_status=?, last_push_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$result['status'], $result['error'], $sub['id']]);
    if (!$result['success']) {
        QueueService::markFailed($queueId);
        if ($result['expired']) db()->prepare("UPDATE push_subscriptions SET status='expired', revoked_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$sub['id']]);
        json_response(['success'=>false,'correlation_id'=>$correlationId,'steps'=>$steps,'failed_step'=>8,'error'=>'Serviço Push recusou o envio.','technical_detail'=>$result['error'],'recommendation'=>'Verifique VAPID, endpoint expirado, HTTPS e certificados.'],422);
    }
    $steps[] = ['step'=>8,'status'=>'ok','message'=>'Serviço Push aceitou'];
    Logger::info('test_push_submitted', ['correlation_id'=>$correlationId,'subscription_id'=>(int)$sub['id'],'http_code'=>$result['status']], 'push');
    json_response(['success'=>true,'correlation_id'=>$correlationId,'http_status'=>$result['status'],'sent_at'=>$sentAt,'subscription_id'=>(int)$sub['id'],'steps'=>$steps]);
} catch (Throwable $e) {
    Logger::exception($e, ['correlation_id'=>$correlationId ?? null], 'push');
    json_response(['success'=>false,'correlation_id'=>$correlationId ?? null,'error'=>$e->getMessage(),'recommendation'=>'Reative as notificações neste aparelho e tente novamente.'],422);
}
