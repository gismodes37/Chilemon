<?php
declare(strict_types=1);

use App\Core\Database;

require_once __DIR__ . '/../public/bootstrap.php'; // si existe
// Si NO tienes bootstrap.php, reemplaza por:
// require_once __DIR__ . '/../vendor/autoload.php'; (si usas composer)
// o bien require_once __DIR__ . '/../app/Core/Database.php' + lo que necesites.

date_default_timezone_set('UTC');

function runCmd(string $cmd): string
{
    $output = [];
    $rc = 0;

    // Importante: el collector se ejecuta como root (cron/systemd),
    // así que no requiere sudo. Si lo ejecutas como otro usuario, sí.
    exec($cmd . ' 2>&1', $output, $rc);

    if ($rc !== 0) {
        throw new RuntimeException("Fallo comando ($rc): $cmd\n" . implode("\n", $output));
    }
    return implode("\n", $output);
}

function ensureSchema(PDO $db): void
{
    $sqlPath = __DIR__ . '/../install/sql/create_tables_sqlite.sql';
    if (!is_file($sqlPath)) {
        throw new RuntimeException("No existe schema SQL: $sqlPath");
    }
    $db->exec(file_get_contents($sqlPath));
}

function parseRptStats(string $txt): array
{
    // Valores por defecto
    $data = [
        'system' => null,
        'signal' => null,
        'uptime' => null,
        'status' => 'unknown',
    ];

    foreach (explode("\n", $txt) as $line) {
        $line = trim($line);

        if (str_starts_with($line, 'System')) {
            // System...........................................: ENABLED
            $parts = explode(':', $line, 2);
            $data['system'] = isset($parts[1]) ? trim($parts[1]) : null;
        }

        if (str_starts_with($line, 'Signal on input')) {
            $parts = explode(':', $line, 2);
            $data['signal'] = isset($parts[1]) ? trim($parts[1]) : null;
        }

        if (str_starts_with($line, 'Uptime')) {
            $parts = explode(':', $line, 2);
            $data['uptime'] = isset($parts[1]) ? trim($parts[1]) : null;
        }
    }

    // Status simple (puedes refinar después)
    if (($data['system'] ?? '') === 'ENABLED') {
        $data['status'] = 'online';
    } else {
        $data['status'] = 'offline';
    }

    return $data;
}

function parseConnectedNodes(string $txt): array
{
    // En rpt nodes, normalmente lista nodos conectados o <NONE>
    $lines = array_map('trim', explode("\n", $txt));
    $nodes = [];

    foreach ($lines as $line) {
        if ($line === '' || str_contains($line, 'CONNECTED NODES') || str_contains($line, '********')) {
            continue;
        }
        if ($line === '<NONE>') {
            break;
        }

        // Si el output trae cosas extra, aquí lo refinamos luego.
        // Por ahora tomamos la línea completa como "nodo"
        $nodes[] = $line;
    }

    return array_values(array_unique($nodes));
}

try {
    $nodeId = getenv('CHILEMON_NODE') ?: '61916';

    $db = Database::getConnection();
    ensureSchema($db);

    $statsTxt = runCmd("asterisk -rx \"rpt stats $nodeId\"");
    $nodesTxt = runCmd("asterisk -rx \"rpt nodes $nodeId\"");

    $stats = parseRptStats($statsTxt);
    $linked = parseConnectedNodes($nodesTxt);

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    // Upsert node
    $stmt = $db->prepare("
        INSERT INTO nodes (node_id, status, signal, system, uptime, last_seen, raw_stats)
        VALUES (:node_id, :status, :signal, :system, :uptime, :last_seen, :raw_stats)
        ON CONFLICT(node_id) DO UPDATE SET
            status = excluded.status,
            signal = excluded.signal,
            system = excluded.system,
            uptime = excluded.uptime,
            last_seen = excluded.last_seen,
            raw_stats = excluded.raw_stats
    ");

    $stmt->execute([
        ':node_id'   => $nodeId,
        ':status'    => $stats['status'],
        ':signal'    => $stats['signal'],
        ':system'    => $stats['system'],
        ':uptime'    => $stats['uptime'],
        ':last_seen' => $now,
        ':raw_stats' => json_encode([
            'rpt_stats' => $statsTxt,
            'rpt_nodes' => $nodesTxt,
        ], JSON_UNESCAPED_UNICODE),
    ]);

    // Links (simplificado)
    $db->prepare("DELETE FROM node_links WHERE node_id = :node_id")->execute([':node_id' => $nodeId]);

    $ins = $db->prepare("
        INSERT OR IGNORE INTO node_links (node_id, linked_node, direction, created_at)
        VALUES (:node_id, :linked_node, :direction, :created_at)
    ");

    foreach ($linked as $ln) {
        $ins->execute([
            ':node_id' => $nodeId,
            ':linked_node' => $ln,
            ':direction' => null,
            ':created_at' => $now,
        ]);
    }

    echo "OK collector: node=$nodeId links=" . count($linked) . " at $now\n";
    exit(0);

} catch (Throwable $e) {
    // Importante: log simple (luego lo mandamos a LOG_PATH)
    fwrite(STDERR, "ERROR collector: " . $e->getMessage() . "\n");
    exit(1);
}
