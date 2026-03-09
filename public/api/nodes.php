<?php
declare(strict_types=1);

/**
 * ChileMon — Nodes API
 * Obtiene nodos conectados (rpt nodes) y registra cambios.
 *
 * IMPORTANTE:
 * - No hay autoload (composer). Por eso el ORDEN de require_once importa.
 * - Database.php DEBE cargarse antes que NodeLogger.php.
 */

require_once __DIR__ . '/../../../config/app.php';

/* -----------------------------
 * Seguridad (sesión)
 * --------------------------- */
require_once ROOT_PATH . '/app/Auth/Auth.php';
use App\Auth\Auth;

/* -----------------------------
 * Core DB + Logger (orden crítico)
 * --------------------------- */
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Core/NodeLogger.php';

/* -----------------------------
 * Servicios / Tracker
 * --------------------------- */
require_once ROOT_PATH . '/app/Services/AslRptService.php';
require_once ROOT_PATH . '/app/Asterisk/NodeTracker.php';

use App\Services\AslRptService;
use App\Asterisk\NodeTracker;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);

    error_log(
        'ChileMon nodes Unauthorized | '
        . 'host=' . ($_SERVER['HTTP_HOST'] ?? '[none]')
        . ' | https=' . ($_SERVER['HTTPS'] ?? '[none]')
        . ' | request_uri=' . ($_SERVER['REQUEST_URI'] ?? '[none]')
        . ' | cookie=' . ($_SERVER['HTTP_COOKIE'] ?? '[none]')
        . ' | session_id=' . session_id()
        . ' | session_user=' . (string)($_SESSION['username'] ?? '[none]')
        . ' | base_path=' . (defined('BASE_PATH') ? BASE_PATH : '[none]')
    );

    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $svc = new AslRptService();
    $raw = (string)$svc->nodes();

    // Parse robusto: captura T12345 en cualquier línea
    $nodes = [];
    if (preg_match_all('/\bT(\d+)\b/', $raw, $m)) {
        $nodes = $m[1];
    }

    // Normaliza para evitar duplicados y tener salida estable
    $nodes = array_values(array_unique(array_map('strval', $nodes)));
    sort($nodes, SORT_NATURAL);

    // Detecta cambios y registra eventos
    (new NodeTracker())->detectChanges($nodes);

    echo json_encode([
        'ok'    => true,
        'count' => count($nodes),
        'nodes' => $nodes,
        'raw'   => $raw,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}