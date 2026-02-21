<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/Services/AslRptService.php';

use App\Services\AslRptService;

header('Content-Type: application/json; charset=utf-8');

try {
    $svc = new AslRptService();
    $raw = $svc->nodes();

    $nodes = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line === '<NONE>') continue;
        if (strpos($line, 'CONNECTED NODES') !== false) continue;
        if (isset($line[0]) && $line[0] === '*') continue;
        $nodes[] = $line;
    }

    echo json_encode([
        'ok' => true,
        'count' => count($nodes),
        'nodes' => $nodes,
        'raw' => $raw,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
