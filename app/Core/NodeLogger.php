<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Database;
use PDO;

final class NodeLogger
{
    public static function log(string $nodeNumber, string $eventType, ?string $details = null): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'INSERT INTO node_events (node_number, event_type, details)
             VALUES (:node, :type, :details)'
        );

        $stmt->execute([
            ':node'    => $nodeNumber,
            ':type'    => $eventType,
            ':details' => $details,
        ]);
    }

    /**
     * Últimos eventos para UI
     */
    public static function latest(int $limit = 15): array
    {
        $db = Database::getConnection();

        $limit = max(1, min(100, $limit)); // safety
        $stmt = $db->prepare(
            'SELECT id, node_number, event_type, details, created_at
             FROM node_events
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return (array)$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}