<?php
declare(strict_types=1);

/**
 * ChileMon — Nodes API
 */

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../app/autoload.php';

use App\Auth\Auth;
use App\Controllers\NodeApiController;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$controller = new NodeApiController();
$response = $controller->getNodes();
echo json_encode($response, JSON_UNESCAPED_UNICODE);