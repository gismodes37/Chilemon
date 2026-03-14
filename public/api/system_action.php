<?php
declare(strict_types=1);

/**
 * public/api/system_action.php
 * -----------------------------------------------
 * Endpoint seguro para acciones de administración del sistema.
 *
 * Acciones soportadas (POST):
 *   action=restart-asterisk  → reinicia Asterisk
 *   action=restart-apache    → reinicia Apache
 *   action=poweroff          → apaga el nodo
 *
 * Seguridad:
 *   - Requiere sesión activa (401 si no está autenticado)
 *   - Valida token CSRF (400 si falla)
 *   - Solo acepta POST (405 si es otro método)
 *   - Las acciones pasan por el wrapper seguro chilemon-rpt
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/autoload.php';

use App\Auth\Auth;
use App\Controllers\SystemController;

header('Content-Type: application/json; charset=utf-8');

// Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Validar CSRF
$token = (string)($_POST['csrf_token'] ?? '');
if ($token !== '' && !Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request (CSRF)']);
    exit;
}

// Validar acción
$action = trim((string)($_POST['action'] ?? ''));
if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetro action requerido']);
    exit;
}

try {
    $controller = new SystemController();
    $result = $controller->execute($action);

    if (!$result['success']) {
        http_response_code(400);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[system_action.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
