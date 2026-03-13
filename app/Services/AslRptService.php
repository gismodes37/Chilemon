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
        'stats', 'nodes', 'connect', 'disconnect',
        'sys-restart-asterisk', 'sys-restart-apache', 'sys-poweroff',
    ];

    private string $wrapper;
    private string $nodeId;

    public function __construct(
        string $wrapperPath = '/usr/local/bin/chilemon-rpt',
        string $nodeId      = ''
    ) {
        $this->wrapper = $wrapperPath;
        
        $configuredNode = defined('ASL_NODE') ? (string) ASL_NODE : (string)(getenv('CHILEMON_NODE') ?: '61916');
        
        // Si el nodeId ingresado manualmente está vacío, usamos el configurado.
        $this->nodeId = $nodeId !== '' ? $nodeId : $configuredNode;

        // Si el nodo actual sigue siendo el 61916 por defecto de instalación
        // y NO estamos en Windows, intentamos descubrir el nodo real de Asterisk.
        if ($this->nodeId === '61916' && strtoupper(substr(PHP_OS_FAMILY, 0, 3)) !== 'WIN') {
            $this->nodeId = $this->discoverLocalNode() ?: '61916';
        }
    }

    private function discoverLocalNode(): ?string
    {
        try {
            $firstNode = null;
            
            // Estrategia 1: Extraer del hostname (ASL3 por lo general nombra al equipo nodeXXXXXX)
            $hostname = (string) gethostname();
            if (preg_match('/^node(\d+)$/i', $hostname, $m)) {
                $firstNode = $m[1];
                error_log("[AslRptService] Autodescubrimiento: Nodo extraído del hostname '{$hostname}' -> {$firstNode}");
                return $firstNode;
            }

            // Estrategia 2: Leer rpt.conf nativamente sin sudo (depende de permisos 644)
            $output = [];
            exec('cat /etc/asterisk/rpt.conf 2>/dev/null', $output);
            
            $inNodesSection = false;
            foreach ($output as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, ';')) {
                    continue;
                }
                if ($line === '[nodes]') {
                    $inNodesSection = true;
                    continue;
                }
                if ($inNodesSection && str_starts_with($line, '[')) {
                    $inNodesSection = false;
                    continue;
                }
                if ($inNodesSection && preg_match('/^(\d+)\s*=\s*/', $line, $matches)) {
                    $nodeText = $matches[1];
                    // Ignorar pseudo-nodos genéricos como Echolink
                    if (str_starts_with($nodeText, '1999')) {
                        continue;
                    }
                    if ($firstNode === null) {
                       $firstNode = $nodeText;
                       break; 
                    }
                }
            }
            
            if ($firstNode !== null) {
                error_log("[AslRptService] Autodescubrimiento: Nodo extraído desde rpt.conf -> {$firstNode}");
            } else {
                error_log("[AslRptService] Fallo autodescubrimiento: ni hostname ni rpt.conf revelaron el nodo.");
            }

            return $firstNode;
        } catch (\Throwable $e) {
            error_log("[AslRptService] Error fatal en autodescubrimiento: " . $e->getMessage());
            return null;
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

    public function disconnect(string $remoteNode): bool
    {
        $this->run('disconnect', $remoteNode);
        return true;
    }

    /**
     * Reinicia el servicio Asterisk de forma segura a través del wrapper.
     */
    public function restartAsterisk(): bool
    {
        $this->runSystem('sys-restart-asterisk');
        return true;
    }

    /**
     * Reinicia el servicio Apache de forma segura a través del wrapper.
     */
    public function restartApache(): bool
    {
        $this->runSystem('sys-restart-apache');
        return true;
    }

    /**
     * Apaga el nodo de forma segura a través del wrapper.
     */
    public function powerOff(): bool
    {
        $this->runSystem('sys-poweroff');
        return true;
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
                return "System...........................................: ENABLED\n" .
                       "Nodes currently connected to us..................: 1000, 2000\n";
            }
            if ($cmd === 'nodes') {
                return "T1000\nT2000\n*3333\n<NONE>\n";
            }
            return "Local simulate success";
        }

        // En Linux, invocamos el wrapper mediante sudo. 
        // El archivo /etc/sudoers.d/chilemon-www-data ya le da permiso a www-data para esto.
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

    /**
     * Ejecuta un comando de sistema a través del wrapper (sin nodeId).
     * Usado para sys-restart-asterisk, sys-restart-apache, sys-poweroff.
     * @throws RuntimeException si exit code != 0
     */
    private function runSystem(string $cmd): string
    {
        if (!in_array($cmd, self::ALLOWED, true)) {
            throw new RuntimeException("Comando de sistema no permitido: {$cmd}");
        }

        $isWindows = strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN';

        if ($isWindows) {
            error_log("[AslRptService] Simulando comando sistema en Windows: {$cmd}");
            return "Simulated {$cmd} OK";
        }

        $fullCmd = 'sudo ' . escapeshellarg($this->wrapper) . ' ' . escapeshellarg($cmd) . ' 2>&1';

        $output   = [];
        $exitCode = -1;

        error_log("[AslRptService] Executing system cmd: {$fullCmd}");
        exec($fullCmd, $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            error_log("[AslRptService] System cmd failed (exit={$exitCode}): {$outputStr}");
            throw new RuntimeException("Comando falló (exit={$exitCode}): {$outputStr}");
        }

        return $outputStr;
    }
}