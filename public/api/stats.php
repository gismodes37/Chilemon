<?php
declare(strict_types=1);

/**
 * public/api/stats.php
 * -----------------------------------------------
 * Obtiene estadísticas del nodo local vía Asterisk rpt.
 *
 * Seguridad:
 *   - Requiere sesión activa
 *   - Rate limiting: 60 requests/min
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Core\RateLimiter;
use App\Services\AslRptService;

header('Content-Type: application/json; charset=utf-8');

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Rate limiting: 60 requests per minute
try {
    RateLimiter::check('api-stats', 60, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $svc = new AslRptService();
    $raw = $svc->stats();
    $parsed = AslRptService::parseKeyValueDots($raw);

    echo json_encode([
        'ok' => true,
        'node' => ASL_NODE,
        'system' => $parsed['System'] ?? null,
        'reverse_patch' => $parsed['Reverse patch/IAXRPT connected'] ?? null,
        'uptime' => $parsed['Uptime'] ?? null,
        'connected_nodes' => $parsed['Nodes currently connected to us'] ?? null,
        'raw' => $raw,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
