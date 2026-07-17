<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'error'=>'Método não permitido.'],405);
try{$data=request_json();$eventId=(int)($data['event_id']??0);$token=(string)($data['participant_token']??'');$sub=$data['subscription']??null;if(!is_array($sub))throw new RuntimeException('Assinatura Push inválida.');
 $p=db()->prepare("SELECT p.*,e.notification_enabled FROM participants p JOIN events e ON e.id=p.event_id WHERE p.event_id=? AND p.public_token=? AND p.status='active' LIMIT 1");$p->execute([$eventId,$token]);$participant=$p->fetch();if(!$participant||!$participant['notification_enabled'])throw new RuntimeException('Notificações indisponíveis para este evento.');
 $endpoint=trim((string)($sub['endpoint']??''));$p256dh=(string)($sub['keys']['p256dh']??'');$auth=(string)($sub['keys']['auth']??'');if(!filter_var($endpoint,FILTER_VALIDATE_URL)||$p256dh===''||$auth==='')throw new RuntimeException('O navegador não forneceu uma assinatura completa.');$hash=hash('sha256',$endpoint);$ua=$_SERVER['HTTP_USER_AGENT']??'';$platform=trim((string)($data['platform']??''));
 db()->prepare("INSERT INTO push_subscriptions (participant_id,event_id,endpoint,endpoint_hash,p256dh_key,auth_key,content_encoding,device_hash,browser,platform,permission_status,status,last_seen_at,created_at,updated_at) VALUES (?,?,?,?,?,?,'aes128gcm',?,?,?,'granted','active',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE participant_id=VALUES(participant_id),event_id=VALUES(event_id),p256dh_key=VALUES(p256dh_key),auth_key=VALUES(auth_key),permission_status='granted',status='active',last_seen_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),revoked_at=NULL")
 ->execute([$participant['id'],$eventId,$endpoint,$hash,$p256dh,$auth,hash('sha256',$endpoint.$ua),mb_substr($ua,0,80),mb_substr($platform!==''?$platform:'Não informado',0,80)]);
 db()->prepare("INSERT INTO consent_logs (participant_id,event_id,consent_type,consent_version,status,ip_address,user_agent,granted_at,created_at) VALUES (?,?,'notifications','1.0','granted',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$participant['id'],$eventId,client_ip(),$ua]);
 json_response(['success'=>true]);
}catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],422);}
