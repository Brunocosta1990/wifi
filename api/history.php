<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$eventId=(int)($_GET['event_id']??0);$stmt=db()->prepare("SELECT title,body,COALESCE(sent_at,scheduled_at) display_at,timezone FROM messages WHERE event_id=? AND status='sent' ORDER BY COALESCE(sent_at,scheduled_at) DESC LIMIT 30");$stmt->execute([$eventId]);$items=[];foreach($stmt->fetchAll() as $m)$items[]=['title'=>$m['title'],'body'=>$m['body'],'sent_at'=>local_from_utc($m['display_at'],$m['timezone'])];json_response(['success'=>true,'messages'=>$items]);
