<?php
declare(strict_types=1);

/**
 * public/api/disconnect.php
 * -----------------------------------------------
 * Desconecta un nodo remoto del nodo local vía Asterisk.
 *
 * Flujo:
 *   1. Valida sesión y CSRF
 *   2. AslRptService::disconnect($node) → chilemon-rpt disconnect <nodeId> <node>
 *   3. Registra evento en SQLite (actividad reciente)
 *   4. Retorna JSON {success, node}
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Services\AslRptService;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

// Validar sesión
Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Requerir rol admin: conectar/desconectar nodos afecta la red de radio
Auth::requireAdmin();

// Validar CSRF
$token = (string)($_POST['csrf_token'] ?? '');
if ($token !== '' && !Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request']);
    exit;
}

// Rate limiting: 20 disconnect requests per minute
try {
    RateLimiter::check('api-disconnect', 20, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validar parámetro node — solo dígitos
$node = trim((string)($_POST['node'] ?? ''));
if ($node === '' || !ctype_digit($node)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetro node inválido']);
    exit;
}

// Whitelist check: si NODE_WHITELIST está definida y no está vacía, solo esos nodos
$whitelist = defined('NODE_WHITELIST') && is_array(NODE_WHITELIST) ? NODE_WHITELIST : [];
if (!empty($whitelist) && !in_array($node, $whitelist, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nodo no autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Ejecutar desconexión real en Asterisk
    $svc = new AslRptService();
    $svc->disconnect($node);

    // Registrar evento en SQLite para actividad reciente
    try {
        $db = \App\Core\Database::getConnection();
        $db->exec("
            CREATE TABLE IF NOT EXISTS node_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                node_id    TEXT NOT NULL,
                event_type TEXT NOT NULL,
                username   TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $stmt = $db->prepare(
            "INSERT INTO node_events (node_id, event_type, username, created_at)
             VALUES (:node, 'disconnect', :user, datetime('now'))"
        );
        $stmt->execute([
            ':node' => $node,
            ':user' => Auth::getUsername() ?: 'system',
        ]);
    } catch (Throwable $dbEx) {
        error_log('[disconnect.php] SQLite error: ' . $dbEx->getMessage());
    }

    echo json_encode(['success' => true, 'node' => $node]);

} catch (Throwable $e) {
    error_log('[disconnect.php] Asterisk error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
