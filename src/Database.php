<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = app_config();
        $db = $config['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Garante que parâmetros, literais e colunas usem a mesma collation.
        self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }
}
