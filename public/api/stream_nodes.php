<?php
declare(strict_types=1);

/**
 * ChileMon — Server-Sent Events (SSE) Endpoint para Nodos
 * Permite mandar el estado actual sin requerir polling constante por el cliente.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/autoload.php';

use App\Auth\Auth;
use App\Controllers\NodeApiController;

// Set headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, must-revalidate');
header('Connection: keep-alive');

// Para SSE de larga vida sin cortar por buffering in PHP/Apache
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
for ($i = 0; $i < ob_get_level(); $i++) {
    @ob_end_flush();
}
@ob_implicit_flush(true);

Auth::startSession();
if (!Auth::isLoggedIn()) {
    echo "event: error\n";
    echo "data: {\"error\": \"Unauthorized\"}\n\n";
    exit;
}

$controller = new NodeApiController();

// Loop para mantener la conexión viva y enviar actualizaciones
$lastHash = '';
$checks = 0;
$maxLifetime = 600; // 10 minutos de vida util máxima para la petición
$start = time();

while (true) {
    if (connection_aborted() || (time() - $start > $maxLifetime)) {
        break;
    }

    $response = $controller->getNodes();
    
    // Convertimos a JSON para fácil comparación y envío
    $jsonResponse = json_encode($response, JSON_UNESCAPED_UNICODE);
    $currentHash = md5($jsonResponse);

    // Solo enviamos si hubo cambios (o es la primera vez)
    if ($currentHash !== $lastHash) {
        echo "data: " . $jsonResponse . "\n\n";
        @ob_flush();
        @flush();
        $lastHash = $currentHash;
    }

    // Ping para mantener la conexión viva si pasa mucho tiempo sin cambios
    $checks++;
    if ($checks % 10 === 0) { // cada ~20 segundos
        echo "event: ping\n";
        echo "data: {\"time\": \"" . date('H:i:s') . "\"}\n\n";
        @ob_flush();
        @flush();
    }

    // Esperar 2 segundos antes del siguiente chequeo
    sleep(2);
}
