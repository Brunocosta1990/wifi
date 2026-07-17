<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (Auth::check()) {
    audit('logout', 'admin_users', (int) $_SESSION['admin_id']);
}
Auth::logout();
redirect('admin/login.php');
