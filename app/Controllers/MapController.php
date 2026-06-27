<?php
declare(strict_types=1);

namespace App\Controllers;

class MapController
{
    /**
     * Register a node on the community map.
     *
     * @param array $data Registration payload (callsign, lat, lng, city, region, registration_token)
     * @return array Response with ok flag and registration id
     */
    public function register(array $data): array
    {
        try {
            $db = \App\Core\Database::getConnection();

            $stmt = $db->prepare(
                "INSERT INTO registrations (node_id, callsign, lat, lng, city, region, registration_token, status)
                 VALUES (:node_id, :callsign, :lat, :lng, :city, :region, :token, 'pending')"
            );
            $stmt->execute([
                ':node_id' => $data['node_id'],
                ':callsign' => $data['callsign'],
                ':lat'      => (float)$data['lat'],
                ':lng'      => (float)$data['lng'],
                ':city'     => $data['city'],
                ':region'   => $data['region'] ?? '',
                ':token'    => $data['registration_token'],
            ]);

            $id = (int)$db->lastInsertId();

            return [
                'ok'       => true,
                'id'       => $id,
                'callsign' => $data['callsign'],
                'status'   => 'pending',
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if a node_id is already registered on the community map.
     *
     * Used by agent dashboards to hide the registration banner
     * when the node is already registered.
     *
     * @param string $nodeId The ASL node ID to look up
     * @return array Response with ok flag and registration status
     */
    public function checkRegistration(string $nodeId): array
    {
        try {
            $db = \App\Core\Database::getConnection();
            $stmt = $db->prepare(
                "SELECT id, node_id, callsign, status, created_at, updated_at
                 FROM registrations WHERE node_id = :node_id ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':node_id' => $nodeId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'ok'         => true,
                    'registered' => true,
                    'status'     => $row['status'],
                    'callsign'   => $row['callsign'],
                    'created_at' => $row['created_at'],
                ];
            }

            return ['ok' => true, 'registered' => false];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
