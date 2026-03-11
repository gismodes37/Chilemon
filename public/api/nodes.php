<?php
declare(strict_types=1);

/**
 * ChileMon — Nodes API
 * Obtiene nodos visibles (rpt nodes) y nodos directos (rpt stats).
 */

require_once __DIR__ . '/../../../config/app.php';

require_once ROOT_PATH . '/app/Auth/Auth.php';
use App\Auth\Auth;

require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Core/NodeLogger.php';

require_once ROOT_PATH . '/app/Services/AslRptService.php';
require_once ROOT_PATH . '/app/Asterisk/NodeTracker.php';

use App\Services\AslRptService;
use App\Asterisk\NodeTracker;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $svc = new AslRptService();

    $rawNodes = (string)$svc->nodes();
    $rawStats = (string)$svc->stats();

    $visibleNodes = AslRptService::parseNodes($rawNodes);
    $directNodes  = AslRptService::parseDirectNodesFromStats($rawStats);

    $visibleLookup = array_fill_keys($visibleNodes, true);
    $directLookup  = array_fill_keys($directNodes, true);

    $allNodes = array_values(array_unique(array_merge($visibleNodes, $directNodes)));
    sort($allNodes, SORT_NATURAL);

    $rows = [];
    foreach ($allNodes as $nodeId) {
        $isDirect  = isset($directLookup[$nodeId]);
        $isVisible = isset($visibleLookup[$nodeId]);

        $rows[] = [
            'node'            => (string)$nodeId,
            'info'            => 'Nodo ' . $nodeId,
            'received'        => '--',
            'link'            => $isDirect ? 'DIRECTO' : ($isVisible ? 'VISIBLE' : '--'),
            'direction'       => $isDirect ? 'IN' : '',
            'connected'       => $isDirect ? 'ACTIVO' : '--',
            'mode'            => 'ASL',
            'online'          => $isDirect,
            'visibility_type' => $isDirect ? 'direct' : 'visible',
        ];
    }

    (new NodeTracker())->detectChanges($allNodes);

    echo json_encode([
        'ok'           => true,
        'count'        => count($rows),
        'nodes'        => $rows,
        'visibleNodes' => $visibleNodes,
        'directNodes'  => $directNodes,
        'rawNodes'     => $rawNodes,
        'rawStats'     => $rawStats,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}