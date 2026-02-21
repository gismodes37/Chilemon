<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use PDO;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Cookie solo para /chilemon/ (importante)
            $cookieParams = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => defined('BASE_PATH') ? BASE_PATH . '/' : '/chilemon/',
                'domain' => $cookieParams['domain'] ?? '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function isLoggedIn(): bool
    {
        self::startSession();
        return isset($_SESSION['user_id'], $_SESSION['username']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function attemptLogin(string $username, string $password): bool
    {
        self::startSession();

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return false;
        if (!password_verify($password, $row['password_hash'])) return false;

        // Anti session fixation
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['username'] = (string)$row['username'];

        return true;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
