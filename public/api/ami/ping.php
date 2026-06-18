<?php
declare(strict_types=1);

/**
 * public/api/ami/ping.php
 * -----------------------------------------------
 * AMI ping test — verifica conectividad con Asterisk AMI.
 *
 * Seguridad:
 *   - Requiere sesión activa
 *   - Rate limiting: 10 requests/min
 *   - NO expone credenciales AMI en la respuesta
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Core\RateLimiter;

header('Content-Type: application/json');

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Rate limiting: 10 pings per minute
try {
    RateLimiter::check('api-ami-ping', 10, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

$fp = @fsockopen(AMI_HOST, AMI_PORT, $errno, $errstr, 3);

if (!$fp) {
    echo json_encode([
        "success" => false,
        "error" => "AMI socket failed: $errstr"
    ]);
    exit;
}

// leer banner
@fgets($fp, 1024);

$login =
"Action: Login\r\n".
"Username: " . AMI_USER . "\r\n".
"Secret: " . AMI_PASS . "\r\n".
"Events: off\r\n\r\n";

@fwrite($fp, $login);

// leer respuesta (solo verificamos éxito, no exponemos raw)
$response = '';
while (!feof($fp)) {
    $line = @fgets($fp, 1024);
    if ($line === false || $line === "\r\n") break;
    $response .= $line;
}

fclose($fp);

$loggedIn = stripos($response, "Response: Success") !== false;

echo json_encode([
    "success" => $loggedIn,
    "message" => $loggedIn ? 'AMI conectado y autenticado' : 'Error de autenticación AMI',
]);
