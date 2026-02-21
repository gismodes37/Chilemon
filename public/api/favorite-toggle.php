<?php
declare(strict_types=1);

session_start();

$publicPath = realpath(__DIR__ . '/..');
$rootPath   = realpath($publicPath . '/..');

require_once $rootPath . '/config/app.php';
require_once $rootPath . '/app/Core/Database.php';


use App\Core\Database;

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$node = trim((string)($_POST['node'] ?? ''));

if ($node === '' || !preg_match('/^[0-9A-Za-z\-_]{3,20}$/', $node)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Node inválido']);
    exit;
}

try {
    $db = Database::getConnection();

    // ¿Ya es favorito?
    $chk = $db->prepare("SELECT 1 FROM favorites WHERE user_id = :uid AND node_id = :node LIMIT 1");
    $chk->execute([':uid' => $userId, ':node' => $node]);
    $exists = (bool)$chk->fetchColumn();

    if ($exists) {
        $del = $db->prepare("DELETE FROM favorites WHERE user_id = :uid AND node_id = :node");
        $del->execute([':uid' => $userId, ':node' => $node]);
        echo json_encode(['success' => true, 'node' => $node, 'favorite' => false]);
        exit;
    }

    $ins = $db->prepare("INSERT INTO favorites (user_id, node_id) VALUES (:uid, :node)");
    $ins->execute([':uid' => $userId, ':node' => $node]);
    echo json_encode(['success' => true, 'node' => $node, 'favorite' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
