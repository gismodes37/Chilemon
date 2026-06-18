<?php
declare(strict_types=1);

/**
 * public/api/delete_node.php
 * -----------------------------------------------
 * Elimina un nodo del registro local (tabla nodes).
 * Usa el sistema de autenticación ChileMon, validación CSRF,
 * y la conexión DB centralizada.
 *
 * Seguridad:
 *   - Requiere sesión activa (Auth::isLoggedIn)
 *   - Valida token CSRF
 *   - Solo acepta POST
 *   - Sanitiza parámetros con ctype_digit
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Core\RateLimiter;

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
if (!Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request (CSRF)']);
    exit;
}

// Rate limiting: 20 delete requests per minute
try {
    RateLimiter::check('api-delete-node', 20, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar parámetro node — solo dígitos (IDs de nodo ASL)
$node = trim((string)($_POST['node'] ?? ''));
if ($node === '' || !ctype_digit($node)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetro node inválido']);
    exit;
}

try {
    $db = Database::getConnection();

    // Eliminar nodo (scope por usuario si aplica, o global si es admin)
    $stmt = $db->prepare("DELETE FROM nodes WHERE node_id = :node");
    $stmt->execute([':node' => $node]);

    // Registrar en node_events
    $stmt = $db->prepare(
        "INSERT INTO node_events (node_id, event_type, username, created_at)
         VALUES (:node, 'delete', :user, datetime('now'))"
    );
    $stmt->execute([
        ':node' => $node,
        ':user' => Auth::getUsername() ?: 'system',
    ]);

    echo json_encode(['success' => true, 'node' => $node]);

} catch (Throwable $e) {
    error_log('[delete_node.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
