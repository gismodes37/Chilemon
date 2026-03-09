<?php
declare(strict_types=1);

namespace App\Asterisk;

use App\Core\Database;
use App\Core\NodeLogger;
use PDO;

/**
 * NodeTracker
 * Detecta cambios en nodos conectados y registra eventos en node_events.
 *
 * Importante:
 * - Usa App\Core\Database (singleton PDO) para no duplicar conexión.
 * - Usa App\Core\NodeLogger para registrar eventos.
 * - Asegura esquema mínimo (nodes / node_events) para evitar fatals.
 */
final class NodeTracker
{
    public function detectChanges(array $currentNodes): void
    {
        // Asegurar que la app cargó constantes (ROOT_PATH, etc.)
        // Este archivo normalmente se ejecuta después de config/app.php
        require_once ROOT_PATH . '/app/Core/Database.php';
        require_once ROOT_PATH . '/app/Core/NodeLogger.php';

        $db = Database::getConnection();

        // Evitar fatals si la DB está nueva o falta alguna tabla
        $this->ensureSchema($db);

        // Normalización: strings únicos
        $currentNodes = array_values(array_unique(array_map('strval', $currentNodes)));

        // Nodos conocidos (en tabla nodes)
        $knownNodes = $db->query("SELECT node_id FROM nodes")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $knownNodes = array_values(array_unique(array_map('strval', $knownNodes)));

        // --- Conectados nuevos: están en current pero no en known
        foreach ($currentNodes as $node) {
            if (!in_array($node, $knownNodes, true)) {
                NodeLogger::log($node, 'connect', 'Nodo conectado');

                $stmt = $db->prepare("
                    INSERT OR IGNORE INTO nodes(node_id, users, last_seen)
                    VALUES(:node, 0, datetime('now'))
                ");
                $stmt->execute([':node' => $node]);
            } else {
                // Si sigue conectado, actualiza last_seen (útil para UI futura)
                $stmt = $db->prepare("UPDATE nodes SET last_seen = datetime('now') WHERE node_id = :node");
                $stmt->execute([':node' => $node]);
            }
        }

        // --- Desconectados: están en known pero no en current
        foreach ($knownNodes as $node) {
            if (!in_array($node, $currentNodes, true)) {
                NodeLogger::log($node, 'disconnect', 'Nodo desconectado');

                $stmt = $db->prepare("DELETE FROM nodes WHERE node_id = :node");
                $stmt->execute([':node' => $node]);
            }
        }
    }

    /**
     * Crea tablas mínimas si no existen (idempotente).
     * Esto evita errores tipo "no such table" en ambientes nuevos.
     */
    private function ensureSchema(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS nodes (
                node_id   TEXT PRIMARY KEY,
                users     INTEGER DEFAULT 0,
                last_seen TEXT
            );
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS node_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                node_number TEXT NOT NULL,
                event_type  TEXT NOT NULL,
                details     TEXT,
                created_at  TEXT DEFAULT (datetime('now'))
            );
        ");
    }
}