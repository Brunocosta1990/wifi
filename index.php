<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
if (Auth::check()) {
    redirect('admin/index.php');
}
redirect('admin/login.php');
