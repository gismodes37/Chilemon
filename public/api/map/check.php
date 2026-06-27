<?php
declare(strict_types=1);

/**
 * GET /api/map/check.php
 * Check if a node_id is already registered on the community map.
 *
 * Used by agent dashboards to hide the registration banner
 * when the node is already registered.
 */

require_once __DIR__ . '/../../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Controllers\MapController;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$nodeId = trim((string)($_GET['node_id'] ?? ''));
if ($nodeId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing node_id parameter']);
    exit;
}

$controller = new MapController();
$result = $controller->checkRegistration($nodeId);
echo json_encode($result);
