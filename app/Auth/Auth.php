<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use PDO;

final class Auth
{
    private const INACTIVITY_TIMEOUT = 1800; // 30 min
    private const MAX_ATTEMPTS   = 5;
    private const WINDOW_SECONDS = 600; // 10 min

    private static ?string $lastError = null;

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Confiar en proxy SOLO si es confiable
        if (self::isFromTrustedProxy()) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
                return true;
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
                return true;
            }
        }

        return !empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443;
    }

    /**
     * define('TRUSTED_PROXIES', ['127.0.0.1']);
     */
    private static function isFromTrustedProxy(): bool
    {
        if (!defined('TRUSTED_PROXIES')) {
            return false;
        }

        $proxies = constant('TRUSTED_PROXIES');
        if (!is_array($proxies)) {
            return false;
        }

        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $remote !== '' && in_array($remote, $proxies, true);
    }

    private static function cookiePath(): string
    {
        if (defined('BASE_PATH')) {
            $p = rtrim((string) BASE_PATH, '/');
            return $p === '' ? '/' : $p . '/';
        }
        return '/';
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Endurecer sesión (antes de session_start)
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');

        $cookieParams = session_get_cookie_params();

        $params = [
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (!empty($cookieParams['domain'])) {
            $params['domain'] = $cookieParams['domain'];
        }

        session_set_cookie_params($params);
        session_start();
    }

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    private static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function windowCutoffIso(): string
    {
        return date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
    }

    private static function cleanupOldAttempts(PDO $db): void
    {
        $cutoff = self::windowCutoffIso();
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
    }

    private static function attemptsCountByIp(PDO $db, string $ip): int
    {
        $cutoff = self::windowCutoffIso();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND created_at >= :cutoff'
        );
        $stmt->execute([':ip' => $ip, ':cutoff' => $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    private static function attemptsCountByUser(PDO $db, string $username): int
    {
        $cutoff = self::windowCutoffIso();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE username = :u AND created_at >= :cutoff'
        );
        $stmt->execute([':u' => $username, ':cutoff' => $cutoff]);
        return (int) $stmt->fetchColumn();
    }

    private static function registerFailedAttempt(PDO $db, string $username, string $ip): void
    {
        $stmt = $db->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (:u, :ip)');
        $stmt->execute([':u' => $username, ':ip' => $ip]);
    }

    private static function clearAttemptsForUserAndIp(PDO $db, string $username, string $ip): void
    {
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE username = :u OR ip_address = :ip');
        $stmt->execute([':u' => $username, ':ip' => $ip]);
    }

    public static function isLoggedIn(): bool
    {
        self::startSession();

        $now = time();

        if (isset($_SESSION['last_activity']) && is_numeric($_SESSION['last_activity'])) {
            if (($now - (int)$_SESSION['last_activity']) > self::INACTIVITY_TIMEOUT) {
                self::logout();
                return false;
            }
        }

        $_SESSION['last_activity'] = $now;

        return isset($_SESSION['user_id'], $_SESSION['username']);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            $base = rtrim(defined('BASE_PATH') ? (string) BASE_PATH : '', '/');
            header('Location: ' . ($base === '' ? '/login.php' : $base . '/login.php'));
            exit;
        }
    }

    public static function attemptLogin(string $username, string $password): bool
    {
        self::$lastError = null;
        self::startSession();

        $db = Database::getConnection();
        self::cleanupOldAttempts($db);

        $ip = self::clientIp();
        $u  = trim($username);

        if ($u === '' || $password === '') {
            self::$lastError = 'Completa usuario y contraseña.';
            return false;
        }

        $byIp   = self::attemptsCountByIp($db, $ip);
        $byUser = self::attemptsCountByUser($db, $u);

        if (max($byIp, $byUser) >= self::MAX_ATTEMPTS) {
            self::$lastError = 'Demasiados intentos. Intenta nuevamente más tarde.';
            return false;
        }

        $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $u]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($password, (string)$row['password_hash'])) {
            self::registerFailedAttempt($db, $u, $ip);
            self::$lastError = 'Usuario o contraseña incorrectos.';
            return false;
        }

        self::clearAttemptsForUserAndIp($db, $u, $ip);
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)$row['id'];
        $_SESSION['username']      = (string)$row['username'];
        $_SESSION['last_activity'] = time();

        return true;
    }

    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            $cookie = [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? self::cookiePath(),
                'secure'   => (bool)($params['secure'] ?? self::isHttps()),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => 'Lax',
            ];
            if (!empty($params['domain'])) {
                $cookie['domain'] = $params['domain'];
            }

            setcookie(session_name(), '', $cookie);
        }

        session_destroy();
    }
}