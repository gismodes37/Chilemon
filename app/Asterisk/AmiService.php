<?php
declare(strict_types=1);

namespace App\Asterisk;

/**
 * AmiService
 * Capa de negocio para ChileMon:
 * - Obtiene nodos conectados
 * - Obtiene stats
 * - Obtiene channels
 * (y luego: conectar / desconectar)
 */
final class AmiService
{
    /**
     * ---------------------------------------
     * Obtener nodos conectados (rpt nodes)
     * ---------------------------------------
     */
    public function getConnectedNodes(
        string $host,
        int $port,
        string $user,
        string $pass,
        string $aslNode,
        int $timeout = 3
    ): array {
        $client = new AmiClient();

        try {
            $client->connect($host, $port, $timeout);
            $client->login($user, $pass);

            $raw = $client->command("rpt nodes " . $aslNode);

            // Parse: detecta <NONE> o lista T#####
            if (stripos($raw, "<NONE>") !== false) {
                return [];
            }

            preg_match_all('/T(\d+)/', $raw, $m);
            return $m[1] ?? [];
        } finally {
            // Limpieza SIEMPRE
            try { $client->logoff(); } catch (\Throwable $e) {}
            $client->close();
        }
    }
}