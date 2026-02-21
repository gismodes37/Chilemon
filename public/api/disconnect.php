<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$node = trim((string)($_POST['node'] ?? ''));
if ($node === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Falta parámetro node']);
    exit;
}

$node = preg_replace('/[^0-9A-Za-z_-]/', '', $node);

$dbFile = DATA_PATH . '/chilemon.sqlite';

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA foreign_keys = ON;");

    // Asegurar tablas mínimas
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

    // Marcar como OFFLINE: last_seen NULL y users 0
    $st = $db->prepare("UPDATE nodes SET last_seen = NULL, users = 0 WHERE node_id = :node");
    $st->execute([':node' => $node]);

    $stCall = $db->prepare("INSERT INTO calls(node_id, action, created_at) VALUES(:node, 'disconnect', datetime('now'))");
    $stCall->execute([':node' => $node]);

    echo json_encode(['success' => true, 'node' => $node]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
