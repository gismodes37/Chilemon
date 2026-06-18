<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Rate limiting: 60 requests per minute
try {
    RateLimiter::check('api-favorites-list', 60, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("
        SELECT id, node_id, alias, description, created_at
        FROM favorites
        WHERE user_id = :uid
        ORDER BY datetime(created_at) DESC, id DESC
    ");
    $stmt->execute([':uid' => Auth::getUserId()]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}