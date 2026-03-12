<?php
declare(strict_types=1);

/**
 * public/index.php — Controlador principal del dashboard ChileMon.
 *
 * Fuente de nodos: AslRptService (Asterisk real) — NO SQLite.
 * SQLite se sigue usando para: favoritos, eventos, usuarios.
 */

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Helpers/System.php';
require_once ROOT_PATH . '/app/Services/AslRptService.php';

use App\Auth\Auth;
use App\Helpers\System;
use App\Services\AslRptService;

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '0.4.0');
}

Auth::startSession();
Auth::requireLogin();

$username = $_SESSION['username'] ?? 'Usuario';
$darkMode = (isset($_COOKIE['chilemon_darkmode']) && $_COOKIE['chilemon_darkmode'] === 'true');

$dbError = null;
$nodos = [];
$estadisticas = [
    'total_nodos'    => 0,
    'nodos_online'   => 0,
    'nodos_idle'     => 0,
    'total_usuarios' => 0,
];

try {
    $svc = new AslRptService();

    $rawNodes = (string)$svc->nodes();
    $rawStats = (string)$svc->stats();

    $visibleNodes = AslRptService::parseNodes($rawNodes);
    $directNodes  = AslRptService::parseDirectNodesFromStats($rawStats);

    /**
     * Tabla principal: SOLO nodos directos
     * Si existe un solo nodo directo, podemos asumir que la red visible
     * pertenece a ese enlace.
     * Si existen varios nodos directos, NO asociamos visibles por fila
     * porque nodes() entrega una bolsa global y no una topología separada.
     */
    $directTableNodes = array_values(array_unique($directNodes));
    sort($directTableNodes, SORT_NATURAL);

    $singleDirectMode = count($directTableNodes) === 1;

    foreach ($directTableNodes as $nodeId) {
        $globalVisibleNodes = array_values(array_diff($visibleNodes, $directTableNodes));
    sort($globalVisibleNodes, SORT_NATURAL);

    if ($singleDirectMode) {
        $remoteVisible = array_values(array_filter(
        $visibleNodes,
        static fn(string $id): bool => $id !== $nodeId
    ));
        sort($remoteVisible, SORT_NATURAL);
        $remoteScope = 'direct';
    } else {
        $remoteVisible = $globalVisibleNodes;
        $remoteScope = 'global';
    }

        $nodos[] = [
            'node'             => (string)$nodeId,
            'node_id'          => (string)$nodeId,
            'info'             => 'Nodo ' . $nodeId,
            'name'             => 'Nodo ' . $nodeId,
            'received'         => '--',
            'link'             => 'DIRECTO',
            'direction'        => 'IN',
            'connected'        => 'Si',
            'mode'             => 'ASL',
            'online'           => true,
            'visibility_type'  => 'direct',
            'remote_visible'   => $remoteVisible,
            'remote_count'     => count($remoteVisible),
            'can_show_remote'  => count($remoteVisible) > 0,
            'remote_scope'     => $remoteScope,
        ];
    }

    $estadisticas['total_nodos']    = count($nodos);
    $estadisticas['nodos_online']   = count($directNodes);
    $estadisticas['nodos_idle']     = 0;
    $estadisticas['total_usuarios'] = 0;

} catch (Throwable $e) {
    $dbError = 'Error al consultar Asterisk: ' . $e->getMessage();
    error_log('[index.php] AslRptService error: ' . $e->getMessage());
}

$systemInfo = System::getSystemInfo();
$ipLists    = System::getIpLists();
$ipv4_list  = $ipLists['ipv4'];
$ipv6_list  = $ipLists['ipv6'];

require __DIR__ . '/views/dashboard.php';