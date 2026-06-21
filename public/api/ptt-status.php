<?php
declare(strict_types=1);

/**
 * public/api/ptt-status.php
 * -----------------------------------------------
 * Proxy status del bridge WebRTC Audio.
 *
 * Consulta el endpoint /health del bridge Python y
 * retorna el estado en JSON. Permite al dashboard
 * mostrar el estado de conexión del bridge.
 *
 * Seguridad:
 *   - Requiere sesión activa
 *   - Rate limiting: 30 requests/min
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../../config/app.php';
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
    RateLimiter::check('api-ptt-status', 30, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

// Consultar bridge /health
$bridgeUrl = sprintf('http://127.0.0.1:%d/health', WEBRTC_PORT);
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $bridgeUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 3,
    CURLOPT_CONNECTTIMEOUT => 2,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    http_response_code(502);
    echo json_encode([
        'ok'      => false,
        'status'  => 'error',
        'error'   => 'Bridge unreachable: ' . $curlError,
    ]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode([
        'ok'     => false,
        'status' => 'error',
        'error'  => "Bridge returned HTTP $httpCode",
    ]);
    exit;
}

// Forward the bridge's response
$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode([
        'ok'     => false,
        'status' => 'error',
        'error'  => 'Invalid bridge response',
    ]);
    exit;
}

echo json_encode([
    'ok'         => true,
    'status'     => $data['status'] ?? 'unknown',
    'registered' => $data['registered'] ?? false,
    'in_call'    => $data['in_call'] ?? false,
    'ptt_active' => $data['ptt_active'] ?? false,
    'peers'      => $data['peers'] ?? 0,
]);
