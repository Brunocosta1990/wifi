<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$event=active_event_by_slug(trim((string)($_GET['slug']??'')));
if(!$event){http_response_code(404);exit;}
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode([
 'id'=>app_url('e/'.$event['slug']),'name'=>$event['name'],'short_name'=>mb_strimwidth($event['name'],0,24,''),
 'description'=>$event['description']?:'Avisos e notificações do evento','start_url'=>app_url('e/'.$event['slug']),
 'scope'=>app_url('/'),'display'=>'standalone','background_color'=>'#ffffff','theme_color'=>$event['cover_color'],
 'icons'=>[['src'=>app_url('assets/icons/icon-192.png'),'sizes'=>'192x192','type'=>'image/png'],['src'=>app_url('assets/icons/icon-512.png'),'sizes'=>'512x512','type'=>'image/png']]
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
