<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AslRptService;
use App\Asterisk\NodeTracker;
use Throwable;

class NodeApiController
{
    public function getNodes(): array
    {
        try {
            $svc = new AslRptService();

            $rawNodes = (string)$svc->nodes();
            $rawStats = (string)$svc->stats();

            $visibleNodes = AslRptService::parseNodes($rawNodes);
            $directNodes  = AslRptService::parseDirectNodesFromStats($rawStats);

            // v0.2.x — Parsear indicadores de actividad RX/TX
            $activity = AslRptService::parseActivity($rawStats);

            $visibleLookup = array_fill_keys($visibleNodes, true);
            $directLookup  = array_fill_keys($directNodes, true);

            $allNodes = array_values(array_unique(array_merge($visibleNodes, $directNodes)));
            sort($allNodes, SORT_NATURAL);

            $rows = [];
            
            // v0.2.x — Cargar favoritos para el usuario actual
            $favorites = [];
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId > 0) {
                try {
                    $db = \App\Core\Database::getConnection();
                    $st = $db->prepare("SELECT node_id, alias FROM favorites WHERE user_id = :uid");
                    $st->execute([':uid' => $userId]);
                    $favorites = $st->fetchAll(\PDO::FETCH_KEY_PAIR); // [ '494780' => 'Mi Nodo', ... ]
                } catch (\Throwable $e) {
                    error_log("Error cargando favoritos en NodeApiController: " . $e->getMessage());
                    $favorites = [];
                }
            }

            foreach ($allNodes as $nodeId) {
                $isDirect   = isset($directLookup[$nodeId]);
                $isVisible  = isset($visibleLookup[$nodeId]);
                $isFav      = isset($favorites[$nodeId]);
                $alias      = (string)($favorites[$nodeId] ?? '');
                
                $nodeInfo = ($alias !== '') ? $alias : 'Nodo ' . $nodeId;

                $rows[] = [
                    'node'            => (string)$nodeId,
                    'is_favorite'     => $isFav,
                    'alias'           => $alias,
                    'info'            => (string)$nodeInfo,
                    'received'        => '--',
                    'link'            => $isDirect ? 'DIRECTO' : ($isVisible ? 'VISIBLE' : '--'),
                    'direction'       => $isDirect ? 'IN' : '',
                    'connected'       => $isDirect ? 'ACTIVO' : '--',
                    'mode'            => 'ASL',
                    'online'          => $isDirect,
                    'visibility_type' => $isDirect ? 'direct' : 'visible',
                    'activity'        => $activity,
                ];
            }

            (new NodeTracker())->detectChanges($allNodes);

            return [
                'ok'           => true,
                'count'        => count($rows),
                'nodes'        => $rows,
                'activity'     => $activity,
                'visibleNodes' => $visibleNodes,
                'directNodes'  => $directNodes,
                'rawNodes'     => $rawNodes,
                'rawStats'     => $rawStats,
            ];

        } catch (Throwable $e) {
            http_response_code(500);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
