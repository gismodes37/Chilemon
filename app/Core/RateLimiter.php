<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * RateLimiter — Middleware reusable para rate limiting por IP.
 *
 * Uso:
 *   RateLimiter::check('api-nodes', 30, 60);  // 30 requests/min
 *
 * Las tablas se crean automáticamente vía Database::ensureSchema().
 */
final class RateLimiter
{
    private const TABLE = 'api_attempts';

    /**
     * Verifica rate limit para una acción + IP.
     *
     * @param string $action     Identificador único de la acción (ej: 'api-nodes', 'login')
     * @param int    $maxAttempts Máximo de intentos permitidos
     * @param int    $windowSec  Ventana de tiempo en segundos
     * @throws \RuntimeException si se excede el límite
     */
    public static function check(string $action, int $maxAttempts = 30, int $windowSec = 60): void
    {
        $ip = self::clientIp();

        // Whitelist de IPs — no aplica rate limiting
        if (defined('RATE_LIMIT_WHITELIST') && is_array(RATE_LIMIT_WHITELIST) && in_array($ip, RATE_LIMIT_WHITELIST, true)) {
            return;
        }

        $db = Database::getConnection();
        $cutoff = date('Y-m-d H:i:s', time() - $windowSec);

        // Limpiar registros viejos (best-effort, cada request)
        $db->prepare("DELETE FROM " . self::TABLE . " WHERE created_at < :cutoff")
           ->execute([':cutoff' => $cutoff]);

        // Contar intentos en la ventana
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM " . self::TABLE . "
             WHERE action = :action AND ip_address = :ip AND created_at >= :cutoff"
        );
        $stmt->execute([
            ':action' => $action,
            ':ip'     => $ip,
            ':cutoff' => $cutoff,
        ]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $maxAttempts) {
            throw new \RuntimeException('Demasiadas solicitudes. Intenta nuevamente más tarde.');
        }

        // Registrar este intento
        $stmt = $db->prepare(
            "INSERT INTO " . self::TABLE . " (action, ip_address) VALUES (:action, :ip)"
        );
        $stmt->execute([
            ':action' => $action,
            ':ip'     => $ip,
        ]);
    }

    /**
     * Helper para usar en APIs: retorna true/false + setea headers.
     *
     * @param string $action
     * @param int    $maxAttempts
     * @param int    $windowSec
     * @return bool true si está dentro del límite, false si excedido
     */
    public static function checkSilent(string $action, int $maxAttempts = 30, int $windowSec = 60): bool
    {
        try {
            self::check($action, $maxAttempts, $windowSec);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    private static function clientIp(): string
    {
        // Para rate limiting usamos REMOTE_ADDR (no confiamos en X-Forwarded-For)
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
