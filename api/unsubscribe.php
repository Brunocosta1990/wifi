<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'error'=>'Método não permitido.'],405);
try{$data=request_json();$eventId=(int)($data['event_id']??0);$token=(string)($data['participant_token']??'');$endpoint=(string)($data['endpoint']??'');$p=db()->prepare("SELECT id FROM participants WHERE event_id=? AND public_token=? LIMIT 1");$p->execute([$eventId,$token]);$participantId=(int)$p->fetchColumn();if(!$participantId)throw new RuntimeException('Cadastro não encontrado.');
 db()->prepare("UPDATE push_subscriptions SET status='revoked',permission_status='denied',revoked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE participant_id=? AND endpoint_hash=?")->execute([$participantId,hash('sha256',$endpoint)]);
 db()->prepare("INSERT INTO consent_logs (participant_id,event_id,consent_type,consent_version,status,ip_address,user_agent,revoked_at,created_at) VALUES (?,?,'notifications','1.0','revoked',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$participantId,$eventId,client_ip(),$_SERVER['HTTP_USER_AGENT']??'']);json_response(['success'=>true]);
}catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],422);}
