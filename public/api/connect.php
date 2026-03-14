<?php
declare(strict_types=1);

/**
 * public/api/connect.php
 * -----------------------------------------------
 * Conecta un nodo remoto al nodo local vía Asterisk.
 *
 * Flujo:
 *   1. Valida sesión y CSRF
 *   2. AslRptService::connect($node) → chilemon-rpt connect <nodeId> <node>
 *   3. Registra evento en SQLite (actividad reciente)
 *   4. Retorna JSON {success, node}
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/autoload.php';

use App\Auth\Auth;
use App\Services\AslRptService;

header('Content-Type: application/json; charset=utf-8');

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
    echo json_encode(['success' => false, 'error' => 'Bad Request']);
    exit;
}

// Validar parámetro node — solo dígitos
$node = trim((string)($_POST['node'] ?? ''));
if ($node === '' || !ctype_digit($node)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetro node inválido']);
    exit;
}

try {
    // Ejecutar conexión real en Asterisk
    $svc = new AslRptService();
    $svc->connect($node);

    // Registrar evento en SQLite para actividad reciente
    try {
        $db = \App\Core\Database::getInstance();
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
             VALUES (:node, 'connect', :user, datetime('now'))"
        );
        $stmt->execute([
            ':node' => $node,
            ':user' => $_SESSION['username'] ?? 'system',
        ]);
    } catch (Throwable $dbEx) {
        error_log('[connect.php] SQLite error: ' . $dbEx->getMessage());
    }

    echo json_encode(['success' => true, 'node' => $node]);

} catch (Throwable $e) {
    error_log('[connect.php] Asterisk error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
