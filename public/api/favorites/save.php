<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';

use App\Auth\Auth;
use App\Core\Database;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$token = (string)($_POST['csrf_token'] ?? '');
if (!Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request']);
    exit;
}

$node = trim((string)($_POST['node_id'] ?? ''));
$alias = trim((string)($_POST['alias'] ?? ''));
$desc = trim((string)($_POST['description'] ?? ''));

// sanitización razonable
$node = preg_replace('/[^0-9]/', '', $node); // nodos ASL: numérico
if ($node === '' || strlen($node) < 3 || strlen($node) > 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nodo inválido']);
    exit;
}

if ($alias !== '') {
    $alias = mb_substr($alias, 0, 60);
}
if ($desc !== '') {
    $desc = mb_substr($desc, 0, 500); // límite sano para UI
}

try {
    $db = Database::getConnection();
    $uid = (int)$_SESSION['user_id'];

    // upsert SQLite
    $stmt = $db->prepare("
        INSERT INTO favorites (user_id, node_id, alias, description)
        VALUES (:uid, :node, :alias, :desc)
        ON CONFLICT(user_id, node_id) DO UPDATE SET
            alias = excluded.alias,
            description = excluded.description
    ");
    $stmt->execute([
        ':uid' => $uid,
        ':node' => $node,
        ':alias' => ($alias === '' ? null : $alias),
        ':desc' => ($desc === '' ? null : $desc),
    ]);

    echo json_encode(['success' => true, 'node_id' => $node], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}