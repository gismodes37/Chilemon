<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

// Autoload simple (sin Composer por ahora)
require_once ROOT_PATH . '/app/Services/AslRptService.php';

use App\Services\AslRptService;

header('Content-Type: application/json; charset=utf-8');

try {
    $svc = new AslRptService();
    $raw = $svc->stats();
    $parsed = AslRptService::parseKeyValueDots($raw);

    echo json_encode([
        'ok' => true,
        'node' => ASL_NODE,
        'system' => $parsed['System'] ?? null,
        'reverse_patch' => $parsed['Reverse patch/IAXRPT connected'] ?? null,
        'uptime' => $parsed['Uptime'] ?? null,
        'connected_nodes' => $parsed['Nodes currently connected to us'] ?? null,
        'raw' => $raw,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
