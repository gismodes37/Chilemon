<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * AslRptService — Wrapper PHP para chilemon-rpt.
 */
final class AslRptService
{
    private const ALLOWED = ['stats', 'nodes', 'connect', 'disconnect'];

    private string $wrapper;
    private string $nodeId;

    public function __construct(
        string $wrapperPath = '/usr/local/bin/chilemon-rpt',
        string $nodeId      = ''
    ) {
        $this->wrapper = $wrapperPath;
        $this->nodeId  = $nodeId !== ''
            ? $nodeId
            : (defined('ASL_NODE') ? (string) ASL_NODE : (string)(getenv('CHILEMON_NODE') ?: '61916'));
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

        $parts = [
            'sudo',
            escapeshellarg($this->wrapper),
            escapeshellarg($cmd),
            escapeshellarg($this->nodeId),
        ];

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