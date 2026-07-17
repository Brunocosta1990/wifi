<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$eventId=(int)($_GET['event_id']??0);$token=(string)($_GET['token']??'');$stmt=db()->prepare("SELECT id,name,status FROM participants WHERE event_id=? AND public_token=? AND status='active' LIMIT 1");$stmt->execute([$eventId,$token]);$p=$stmt->fetch();if(!$p)json_response(['success'=>false,'error'=>'Cadastro não encontrado.'],404);
$count=db()->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE participant_id=? AND status='active'");$count->execute([$p['id']]);json_response(['success'=>true,'participant'=>$p,'active_subscriptions'=>(int)$count->fetchColumn()]);
