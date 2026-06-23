<?php
declare(strict_types=1);

/**
 * public/api/ptt-ws-token.php
 * -----------------------------------------------
 * Genera un token HMAC para autenticarse vía WebSocket
 * contra el bridge WebRTC Audio.
 *
 * El token tiene formato: "<username>:<hex_signature>"
 * donde hex_signature son los primeros 16 caracteres hex
 * de HMAC-SHA256(username, webrtc_secret).
 *
 * El bridge valida este token al recibir una conexión WS.
 *
 * Seguridad:
 *   - Requiere sesión activa
 *   - Rate limiting: 10 tokens/min por usuario
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Rate limiting
try {
    RateLimiter::check('api-ptt-ws-token', 10, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Verificar que el secreto está configurado
if (WEBRTC_SECRET === '') {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'WebRTC bridge secret not configured',
    ]);
    exit;
}

// Generar token HMAC — first 16 hex chars of HMAC-SHA256(username, webrtc_secret)
// This MUST match bridge server.py validate_ws_token(): hexdigest()[:16]
$username = Auth::getUsername();
$hexSignature = hash_hmac('sha256', $username, WEBRTC_SECRET, false);
$truncated = substr($hexSignature, 0, 16);
$token = sprintf('%s:%s', $username, $truncated);

echo json_encode([
    'ok'    => true,
    'token' => $token,
    'expires_in' => 3600,  // 1 hour validity hint to the client
]);
