<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$result=Scheduler::run(25);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
