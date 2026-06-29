<?php
declare(strict_types=1);

/**
 * public/api/check-update.php
 * -----------------------------------------------
 * Endpoint para verificar actualizaciones de ChileMon.
 *
 * Compara el HEAD local de Git contra origin/main:
 *   1. Ejecuta git fetch origin (via wrapper)
 *   2. Compara hashes local vs remoto
 *   3. Retorna JSON con el resultado
 *
 * GET: require auth, rate-limited 30/60s
 *
 * Respuestas:
 *   200 → { ok, update_available, local_commit, remote_commit, summary }
 *   401 → No autenticado
 *   429 → Rate limit excedido
 *   500 → Error interno
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Core\RateLimiter;
use App\Services\UpdateService;

header('Content-Type: application/json; charset=utf-8');

// Solo GET
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Rate limiting: 30 requests per 60 seconds
try {
    RateLimiter::check('check-update', 30, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new UpdateService();
    $result  = $service->check();

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[check-update.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
