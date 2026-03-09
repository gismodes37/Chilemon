<?php
declare(strict_types=1);

/**
 * ChileMon — AMI Nodes API
 * Obtiene nodos conectados vía AMI (rpt nodes) y registra cambios (eventos).
 */

/* -------------------------------------------------------
 * Bootstrap base del sistema (constantes, paths, BASE_URL)
 * ----------------------------------------------------- */
require_once __DIR__ . '/../../../config/app.php';

/* -------------------------------------------------------
 * Seguridad: sesión + autorización
 * ----------------------------------------------------- */
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Core/NodeLogger.php';

use App\Auth\Auth;

header('Content-Type: application/json; charset=utf-8');

Auth::startSession();

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------------
 * Servicios: ASL RPT (AMI) + Tracker de cambios
 * ----------------------------------------------------- */
require_once ROOT_PATH . '/app/Services/AslRptService.php';
require_once ROOT_PATH . '/app/Asterisk/NodeTracker.php';

use App\Services\AslRptService;
use App\Asterisk\NodeTracker;

try {
    /* -------------------------------------------------------
     * Ejecutar comando remoto: rpt nodes <ASL_NODE>
     * ----------------------------------------------------- */
    $svc = new AslRptService();
    $raw = (string)$svc->nodes(); // Debe devolver el output del "rpt nodes"

    /* -------------------------------------------------------
     * Parseo: extraer números desde tokens tipo "T12345"
     * (robusto para múltiples líneas y comas)
     * ----------------------------------------------------- */
    $nodes = [];
    if (preg_match_all('/\bT(\d+)\b/', $raw, $m)) {
        $nodes = $m[1]; // solo números
    }

    // Normalizar: únicos + ordenados (opcional pero limpio)
    $nodes = array_values(array_unique(array_map('strval', $nodes)));
    sort($nodes, SORT_NATURAL);

    /* -------------------------------------------------------
     * Registrar cambios detectados (connect/disconnect) en DB
     * ----------------------------------------------------- */
    $tracker = new NodeTracker();
    $tracker->detectChanges($nodes);

    /* -------------------------------------------------------
     * Respuesta JSON
     * ----------------------------------------------------- */
    echo json_encode([
        'ok'    => true,
        'count' => count($nodes),
        'nodes' => $nodes,
        'raw'   => $raw,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}