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
$node = preg_replace('/[^0-9]/', '', $node);
if ($node === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nodo inválido']);
    exit;
}

try {
    $db = Database::getConnection();
    $stmt = $db->prepare("DELETE FROM favorites WHERE user_id = :uid AND node_id = :node");
    $stmt->execute([':uid' => (int)$_SESSION['user_id'], ':node' => $node]);

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}