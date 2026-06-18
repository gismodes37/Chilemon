<?php
declare(strict_types=1);

/**
 * public/api/health.php
 * -----------------------------------------------
 * Health check endpoint for uptime monitoring.
 *
 * - No login required (it IS a health check)
 * - Rate limited: 30 requests/minute
 * - Returns JSON with system version info
 * - Handles DB failures gracefully → HTTP 503
 * -----------------------------------------------
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Core\Database;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

// Rate limiting: 30 requests per minute
try {
    RateLimiter::check('api-health', 30, 60);
} catch (\RuntimeException $e) {
    http_response_code(429);
    echo json_encode([
        'status' => 'error',
        'error'  => 'Rate limit exceeded',
    ]);
    exit;
}

// Build base response
$response = [
    'status'  => 'ok',
    'version' => defined('APP_VERSION') ? APP_VERSION : '0.4.0',
    'php'     => PHP_VERSION,
    'sqlite'  => '',
    'time'    => date('Y-m-d H:i:s'),
];

// Try to reach the database
try {
    $db = Database::getConnection();
    $response['sqlite'] = (string) $db->query("SELECT sqlite_version()")->fetchColumn();
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'degraded',
        'error'  => 'Database error: ' . $e->getMessage(),
    ]);
    exit;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
