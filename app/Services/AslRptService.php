<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * AslRptService — Wrapper PHP para chilemon-rpt.
 */
final class AslRptService
{
    private const ALLOWED = [
        'stats',
        'nodes',
        'connect',
        'disconnect',
        'restart-asterisk',
        'restart-apache',
        'poweroff'
    ];

    private string $wrapper;
    private string $nodeId;

    public function __construct(
        string $wrapperPath = '/usr/local/bin/chilemon-rpt',
        string $nodeId      = ''
    ) {
        $this->wrapper = $wrapperPath;
        $this->nodeId  = $nodeId !== ''
            ? $nodeId
            : (defined('ASL_NODE') ? (string) ASL_NODE : (string)(getenv('CHILEMON_NODE') ?: ''));
            if ($this->nodeId === '') {
            throw new RuntimeException('Nodo local no configurado en ChileMon.');
            }
    }

    public function stats(): string
    {
        return $this->run('stats');
    }

    public function nodes(): string
    {
        return $this->run('nodes');
    }

    public function connect(string $remoteNode): bool
    {
        $this->run('connect', $remoteNode);
        return true;
    }

    public function disconnect(string $remoteNode): string
    {
        return $this->run('disconnect', $remoteNode);
    }

    /**
     * Reinicia el servicio Asterisk mediante el wrapper.
     */
    public function restartAsterisk(): string
    {
        return $this->run('restart-asterisk', $this->nodeId);
    }

    /**
     * Reinicia el servidor web Apache.
     */
    public function restartApache(): string
    {
        return $this->run('restart-apache', $this->nodeId);
    }

    /**
     * Apaga el nodo completamente (hardware).
     */
    public function powerOff(): string
    {
        return $this->run('poweroff', $this->nodeId);
    }

    /**
     * Parsea output simple de "rpt nodes" → array de IDs numéricos sin prefijo T/R/L.
     * Ejemplo entrada: "T1001, T2450, T52764"
     * Ejemplo salida:  ["1001", "2450", "52764"]
     */
    public static function parseNodes(string $raw): array
    {
        $nodes = [];
        foreach (preg_split('/[\r\n,]+/', $raw) as $token) {
            $token = trim($token);
            if ($token === '' || $token === '<NONE>') {
                continue;
            }
            if (strpos($token, 'CONNECTED NODES') !== false) {
                continue;
            }
            if (isset($token[0]) && $token[0] === '*') {
                continue;
            }

            $nodeId = ltrim($token, 'TRLtrl');
            if ($nodeId !== '' && ctype_digit($nodeId)) {
                $nodes[] = $nodeId;
            }
        }

        return array_values(array_unique($nodes));
    }

    /**
     * Parsea stats tipo:
     * Nodes currently connected to us..................: 54614
     * o eventualmente múltiples nodos separados por coma.
     */
    public static function parseDirectNodesFromStats(string $raw): array
    {
        $matches = [];
        if (!preg_match('/Nodes currently connected to us\.{3,}:\s*(.*)$/mi', $raw, $matches)) {
            return [];
        }

        $value = trim((string)($matches[1] ?? ''));
        if ($value === '' || strtoupper($value) === 'N/A' || strtoupper($value) === '<NONE>') {
            return [];
        }

        $nodes = [];
        foreach (preg_split('/[\s,]+/', $value) as $token) {
            $token = trim($token);
            if ($token !== '' && ctype_digit($token)) {
                $nodes[] = $token;
            }
        }

        return array_values(array_unique($nodes));
    }

    /**
     * Parsea líneas tipo:
     *   "System...........................................: ENABLED"
     */
    public static function parseKeyValueDots(string $raw): array
    {
        $data  = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        foreach ($lines as $line) {
            if (strpos($line, '...') === false || strpos($line, ':') === false) {
                continue;
            }

            $parts = preg_split('/\.{3,}:\s*/', $line, 2);
            if (!$parts || count($parts) < 2) {
                continue;
            }

            $k = trim($parts[0]);
            $v = trim($parts[1]);
            if ($k !== '') {
                $data[$k] = $v;
            }
        }

        return $data;
    }

    /**
     * Parsea rpt stats enfocado en indicadores de actividad RX/TX.
     *
     * Campos extraídos:
     *   - Signal on input  → rx (bool)
     *   - TX time today    → tx_time_today (int, en segundos)
     *   - Keyups today     → keyups_today (int)
     *
     * @return array{
     *   rx: bool,
     *   tx_time_today: int,
     *   tx_time_raw: string,
     *   keyups_today: int,
     *   signal_raw: string,
     *   timestamp: int
     * }
     */
    public static function parseActivity(string $rawStats): array
    {
        $kv = self::parseKeyValueDots($rawStats);

        // --- Signal on input → RX ---
        $signalRaw = $kv['Signal on input'] ?? '';
        $rx = false;
        if ($signalRaw !== '') {
            $upper = strtoupper(trim($signalRaw));
            // "YES", cualquier número > 0, o texto distinto de "NO"/"0"/vacío
            $rx = ($upper === 'YES')
                || (is_numeric($signalRaw) && (int)$signalRaw > 0)
                || ($upper !== 'NO' && $upper !== '0' && $upper !== '');
        }

        // --- TX time today → segundos ---
        $txRaw = $kv['TX time today'] ?? '';
        $txSeconds = self::parseTimeToSeconds($txRaw);

        // --- Keyups today ---
        $keyupsRaw = $kv['Keyups today'] ?? '0';
        $keyups = (int)filter_var($keyupsRaw, FILTER_SANITIZE_NUMBER_INT);

        return [
            'rx'             => $rx,
            'tx_time_today'  => $txSeconds,
            'tx_time_raw'    => trim($txRaw),
            'keyups_today'   => max(0, $keyups),
            'signal_raw'     => trim($signalRaw),
            'timestamp'      => time(),
        ];
    }

    /**
     * Convierte string de tiempo (mm:ss, hh:mm:ss, hh:mm:ss:ms o solo segundos) a int segundos.
     * Tolerante: si no puede parsear, retorna 0.
     */
    private static function parseTimeToSeconds(string $time): int
    {
        $time = trim($time);
        if ($time === '' || strtoupper($time) === 'N/A') {
            return 0;
        }

        // AllStarLink puede enviar hasta 4 partes (hh:mm:ss:ms)
        $parts = explode(':', $time);
        $parts = array_map('intval', $parts);

        return match (count($parts)) {
            4 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2], // Ignorar ms
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            1 => $parts[0],
            default => 0,
        };
    }

    /**
     * Ejecuta el wrapper con exec() y valida exit code.
     * @throws RuntimeException si exit code != 0
     */
    private function run(string $cmd, string $extraArg = ''): string
    {
        if (!in_array($cmd, self::ALLOWED, true)) {
            throw new RuntimeException("Comando no permitido: {$cmd}");
        }

        if ($extraArg !== '' && !ctype_digit($extraArg)) {
            throw new RuntimeException("Argumento inválido: {$extraArg}");
        }

        $isWindows = strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN';

        // En entornos Windows (desarrollo), Asterisk y chilemon-rpt no existen.
        // Devolvemos datos simulados para no romper el dashboard y evitar errores de "Ruta no encontrada".
        if ($isWindows) {
            if ($cmd === 'stats') {
                // Mock enriquecido con campos de actividad para desarrollo visual
                $mockRx     = rand(0, 1) ? 'YES' : 'NO';
                $mockKeyups = (string)rand(0, 50);
                $mockTx     = sprintf('%02d:%02d', rand(0, 5), rand(0, 59));

                return "System...........................................: ENABLED\n" .
                       "Nodes currently connected to us..................: 1000, 2000\n" .
                       "Signal on input..................................: {$mockRx}\n" .
                       "Keyups today.....................................: {$mockKeyups}\n" .
                       "TX time today....................................: {$mockTx}\n";
            }
            if ($cmd === 'nodes') {
                return "T1000\nT2000\n*3333\n<NONE>\n";
            }
            // Mocks para acciones de sistema en Windows
            if (in_array($cmd, ['restart-asterisk', 'restart-apache', 'poweroff'], true)) {
                return "Simulated success for action: {$cmd}";
            }
            // Para 'connect' y 'disconnect'
            return "Local simulate success";
        }

        $parts = ['sudo'];

        $parts[] = escapeshellarg($this->wrapper);
        $parts[] = escapeshellarg($cmd);
        $parts[] = escapeshellarg($this->nodeId);

        if ($extraArg !== '') {
            $parts[] = escapeshellarg($extraArg);
        }

        $fullCmd  = implode(' ', $parts) . ' 2>&1';
        $output   = [];
        $exitCode = -1;

        error_log("[AslRptService] Executing: {$fullCmd}");
        exec($fullCmd, $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            error_log("[AslRptService] Failed (exit={$exitCode}): {$outputStr}");
            throw new RuntimeException("Wrapper falló (exit={$exitCode}): {$outputStr}");
        }

        return $outputStr;
    }
}