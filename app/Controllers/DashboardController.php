<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\System;
use App\Services\AslRptService;
use Throwable;

class DashboardController
{
    public function index(): array
    {
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
             */
            $directTableNodes = array_values(array_unique($directNodes));
            sort($directTableNodes, SORT_NATURAL);

            $singleDirectMode = count($directTableNodes) === 1;

            // v0.2.x — Cargar favoritos para el usuario actual
            $favorites = [];
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId > 0) {
                try {
                    $db = \App\Core\Database::getConnection();
                    $st = $db->prepare("SELECT node_id, alias FROM favorites WHERE user_id = :uid");
                    $st->execute([':uid' => $userId]);
                    $favorites = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
                } catch (\Throwable $e) {
                    error_log("Error cargando favoritos en DashboardController: " . $e->getMessage());
                }
            }

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

                $isFav = isset($favorites[$nodeId]);
                $alias = (string)($favorites[$nodeId] ?? '');
                $nodeName = ($alias !== '') ? $alias : 'Nodo ' . $nodeId;

                $nodos[] = [
                    'node'             => (string)$nodeId,
                    'node_id'          => (string)$nodeId,
                    'is_favorite'      => $isFav,
                    'alias'            => $alias,
                    'info'             => (string)$nodeName,
                    'name'             => (string)$nodeName,
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
            error_log('[DashboardController] AslRptService error: ' . $e->getMessage());
        }

        $systemInfo = System::getSystemInfo();
        $ipLists    = System::getIpLists();
        $ipv4_list  = $ipLists['ipv4'];
        $ipv6_list  = $ipLists['ipv6'];

        return [
            'nodos' => $nodos,
            'estadisticas' => $estadisticas,
            'dbError' => $dbError,
            'systemInfo' => $systemInfo,
            'ipv4_list' => $ipv4_list,
            'ipv6_list' => $ipv6_list
        ];
    }
}
