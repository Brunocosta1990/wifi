<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['success'=>false,'error'=>'Método não permitido.'],405);
try{
 $data=request_json();if(!empty($data['website']))json_response(['success'=>true,'participant_token'=>random_token(24)]);
 $eventId=(int)($data['event_id']??0);$stmt=db()->prepare("SELECT * FROM events WHERE id=? AND status='active' LIMIT 1");$stmt->execute([$eventId]);$event=$stmt->fetch();
 if(!$event||!$event['registration_enabled'])throw new RuntimeException('O cadastro deste evento está indisponível.');
 $name=trim((string)($data['name']??''));$email=strtolower(trim((string)($data['email']??'')));$phone=trim((string)($data['phone']??''));$type=trim((string)($data['participant_type']??''));
 if($name===''||mb_strlen($name)>160)throw new RuntimeException('Informe seu nome.');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');if(empty($data['terms'])||empty($data['privacy']))throw new RuntimeException('É necessário aceitar os termos e a privacidade.');
 $token=random_token(32);db()->beginTransaction();
 db()->prepare("INSERT INTO participants (event_id,public_token,name,email,phone,participant_type,registration_ip,user_agent,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$eventId,$token,$name,$email?:null,$phone?:null,$type?:null,client_ip(),$_SERVER['HTTP_USER_AGENT']??'']);$participantId=(int)db()->lastInsertId();
 $consent=db()->prepare("INSERT INTO consent_logs (participant_id,event_id,consent_type,consent_version,status,ip_address,user_agent,granted_at,created_at) VALUES (?,?,?,'1.0','granted',?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");foreach(['terms','privacy'] as $ct)$consent->execute([$participantId,$eventId,$ct,client_ip(),$_SERVER['HTTP_USER_AGENT']??'']);db()->commit();
 json_response(['success'=>true,'participant_token'=>$token,'participant'=>['id'=>$participantId,'name'=>$name]]);
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();json_response(['success'=>false,'error'=>$e->getMessage()],422);}
