<?php
declare(strict_types=1);

final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_name'] = $user['name'];
        $_SESSION['admin_email'] = $user['email'];
        $_SESSION['organization_id'] = (int) $user['organization_id'];
        db()->prepare('UPDATE admin_users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    public static function requireAdmin(): void
    {
        if (!self::check()) {
            redirect('admin/login.php');
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
