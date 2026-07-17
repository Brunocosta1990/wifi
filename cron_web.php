<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$token=(string)($_GET['token']??'');
if(!$token||!hash_equals((string)app_config()['cron_token'],$token)){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Token inválido.']);exit;}
$result=Scheduler::run(25);echo json_encode(['success'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
