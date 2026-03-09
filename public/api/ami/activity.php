<?php
declare(strict_types=1);

/**
 * ChileMon — AMI Activity API
 * Devuelve últimos eventos (node_events) para la UI.
 */

require_once __DIR__ . '/../../../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Core/NodeLogger.php';

use App\Auth\Auth;
use App\Core\NodeLogger;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = (int)($_GET['limit'] ?? 15);
$limit = max(1, min(100, $limit));

try {
    $items = NodeLogger::latest($limit);

    echo json_encode([
        'ok' => true,
        'count' => count($items),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}