<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
try {
    $eventId=(int)($_GET['event_id']??0); $token=(string)($_GET['token']??''); $sentAt=(string)($_GET['sent_at']??''); $correlationId=(string)($_GET['correlation_id']??'');
    $stmt=db()->prepare("SELECT ps.last_push_sent_at,ps.last_push_received_at,ps.last_push_http_status,ps.last_push_error FROM push_subscriptions ps JOIN participants p ON p.id=ps.participant_id WHERE ps.event_id=? AND p.public_token=? AND ps.status='active' ORDER BY ps.id DESC LIMIT 1");
    $stmt->execute([$eventId,$token]); $row=$stmt->fetch(); if(!$row) json_response(['success'=>false,'error'=>'Assinatura não encontrada.'],404);
    $received=false; if(!empty($row['last_push_received_at'])&&$sentAt!==''){ $receivedTimestamp=strtotime($row['last_push_received_at'].' UTC'); $sentTimestamp=strtotime($sentAt); $received=$receivedTimestamp!==false&&$sentTimestamp!==false&&$receivedTimestamp>=($sentTimestamp-2); }
    $displayed=false;
    if($correlationId!=='') { $q=db()->prepare("SELECT COUNT(*) FROM system_logs WHERE correlation_id=? AND message IN ('notification_show_success','push_received','device_received')"); $q->execute([$correlationId]); $displayed=(int)$q->fetchColumn()>0; }
    json_response(['success'=>true,'received'=>$received,'displayed'=>$displayed,'last_push_sent_at'=>$row['last_push_sent_at'],'last_push_received_at'=>$row['last_push_received_at'],'http_status'=>$row['last_push_http_status'],'error'=>$row['last_push_error']]);
} catch(Throwable $e){ Logger::exception($e, [], 'push'); json_response(['success'=>false,'error'=>$e->getMessage()],422); }
