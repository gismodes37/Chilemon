<?php
declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use PDO;

final class Auth
{
    // Etapa 1
    private const INACTIVITY_TIMEOUT = 1800;

    // Etapa 2
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 600; // 10 min
    private static ?string $lastError = null;

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') return true;
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        return false;
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
        if (session_status() === PHP_SESSION_ACTIVE) return;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', self::isHttps() ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');

        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'domain'   => $cookieParams['domain'] ?? '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    private static function clientIp(): string
    {
        // Si NO usas proxy, REMOTE_ADDR es suficiente y más seguro.
        // Para evitar spoofing, no confíes en X_FORWARDED_FOR salvo que tú controles el proxy.
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function windowCutoffIso(): string
    {
        // SQLite compara bien ISO "YYYY-MM-DD HH:MM:SS"
        return date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);
    }

    private static function attemptsCount(PDO $db, string $username, string $ip): int
    {
        $cutoff = self::windowCutoffIso();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip
               AND username = :u
               AND created_at >= :cutoff"
        );
        $stmt->execute([':ip' => $ip, ':u' => $username, ':cutoff' => $cutoff]);
        return (int)$stmt->fetchColumn();
    }

    private static function registerFailedAttempt(PDO $db, string $username, string $ip): void
    {
        $stmt = $db->prepare(
            "INSERT INTO login_attempts (username, ip_address) VALUES (:u, :ip)"
        );
        $stmt->execute([':u' => $username, ':ip' => $ip]);
    }

    private static function clearAttempts(PDO $db, string $username, string $ip): void
    {
        $stmt = $db->prepare(
            "DELETE FROM login_attempts WHERE username = :u AND ip_address = :ip"
        );
        $stmt->execute([':u' => $username, ':ip' => $ip]);
    }

    private static function cleanupOldAttempts(PDO $db): void
    {
        $cutoff = self::windowCutoffIso();
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE created_at < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
    }

    public static function isLoggedIn(): bool
    {
        self::startSession();

        $now = time();
        if (isset($_SESSION['last_activity']) && is_int($_SESSION['last_activity'])) {
            if (($now - $_SESSION['last_activity']) > self::INACTIVITY_TIMEOUT) {
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
            $login = rtrim(defined('BASE_PATH') ? (string)BASE_PATH : '', '/') . '/login.php';
            header('Location: ' . ($login === '/login.php' ? '/login.php' : $login));
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

        // Rate limit BEFORE verificar password (evita brute force)
        $count = self::attemptsCount($db, $u, $ip);
        if ($count >= self::MAX_ATTEMPTS) {
            self::$lastError = "Demasiados intentos. Espera 10 minutos e intenta nuevamente.";
            return false;
        }

        $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $u]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($password, (string)$row['password_hash'])) {
            self::registerFailedAttempt($db, $u, $ip);

            $remain = max(0, self::MAX_ATTEMPTS - ($count + 1));
            self::$lastError = $remain === 0
                ? "Demasiados intentos. Espera 10 minutos e intenta nuevamente."
                : "Usuario o contraseña incorrectos. Intentos restantes: {$remain}.";

            return false;
        }

        // Login OK: limpiar intentos y fijar sesión
        self::clearAttempts($db, $u, $ip);
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
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?? self::cookiePath(),
                'domain'   => $params['domain'] ?? '',
                'secure'   => (bool)($params['secure'] ?? self::isHttps()),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }
}