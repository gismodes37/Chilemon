<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();

// Si no hay sesión válida, 401
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// (Recomendado) Validar CSRF si viene (tu JS lo manda siempre)
$token = (string)($_POST['csrf_token'] ?? '');
if ($token !== '' && !Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request']);
    exit;
}

$node = trim((string)($_POST['node'] ?? ''));
if ($node === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Falta parámetro node']);
    exit;
}

// Sanitizar
$node = preg_replace('/[^0-9A-Za-z_-]/', '', $node);

$dbFile = DATA_PATH . '/chilemon.sqlite';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA foreign_keys = ON;");

    // Tablas mínimas
    $db->exec("
        CREATE TABLE IF NOT EXISTS nodes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            node_id TEXT NOT NULL UNIQUE,
            users INTEGER DEFAULT 0,
            last_seen TEXT DEFAULT NULL
        );
        CREATE TABLE IF NOT EXISTS calls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            node_id TEXT NOT NULL,
            action TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
    ");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_nodes_node_id ON nodes(node_id);");

    // Upsert compatible
    $stIns = $db->prepare("INSERT OR IGNORE INTO nodes(node_id, users, last_seen) VALUES(:node, 0, datetime('now'))");
    $stIns->execute([':node' => $node]);

    $stUp = $db->prepare("UPDATE nodes SET last_seen = datetime('now') WHERE node_id = :node");
    $stUp->execute([':node' => $node]);

    $stCall = $db->prepare("INSERT INTO calls(node_id, action, created_at) VALUES(:node, 'connect', datetime('now'))");
    $stCall->execute([':node' => $node]);

    echo json_encode(['success' => true, 'node' => $node]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}