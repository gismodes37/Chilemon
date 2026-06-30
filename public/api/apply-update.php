<?php
declare(strict_types=1);

/**
 * public/api/apply-update.php
 * -----------------------------------------------
 * Endpoint para aplicar una actualización de ChileMon.
 *
 * Ejecuta:
 *   1. git pull origin main (via wrapper, con stash automático)
 *   2. systemctl restart chilemon-webrtc
 *   3. systemctl reload apache2
 *
 * POST: require admin + CSRF + rate-limited 5/60s
 *
 * Seguridad:
 *   - Requiere sesión activa (401 si no autenticado)
 *   - Requiere rol admin (403 si no)
 *   - Valida token CSRF vía POST o X-CSRF-Token header (400 si falla)
 *   - Rate limiting: 5 requests cada 60 segundos (429 si excede)
 *
 * Respuestas:
 *   200 → { success, action, message, stashed, commit }
 *   400 → CSRF inválido o parámetros faltantes
 *   403 → No autorizado (no admin)
 *   405 → Método no permitido
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

// Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Requerir rol admin
Auth::requireAdmin();

// Validar CSRF: soporta POST body O header X-CSRF-Token (API consistency)
$token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($token === '' || !Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request (CSRF)'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Rate limiting: 5 requests per 60 seconds
try {
    RateLimiter::check('apply-update', 5, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new UpdateService();
    $result  = $service->apply();

    if (!$result['success']) {
        http_response_code(400);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[apply-update.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
