<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AslRptService
{
    private string $wrapper;

    public function __construct(string $wrapperPath = '/usr/local/bin/chilemon-rpt')
    {
        $this->wrapper = $wrapperPath;
    }

    public function stats(): string
    {
        return $this->run('stats');
    }

    public function nodes(): string
    {
        return $this->run('nodes');
    }

    private function run(string $cmd): string
    {
        // Solo permitimos stats/nodes
        if (!in_array($cmd, ['stats', 'nodes'], true)) {
            throw new RuntimeException('Command not allowed');
        }

        $full = sprintf('sudo %s %s 2>&1', escapeshellcmd($this->wrapper), escapeshellarg($cmd));
        $out = shell_exec($full);

        if ($out === null) {
            throw new RuntimeException('Wrapper execution failed');
        }

        return $out;
    }

    public static function parseKeyValueDots(string $raw): array
    {
        // Parsea líneas tipo: "System...........................................: ENABLED"
        $data = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        foreach ($lines as $line) {
            if (strpos($line, '...') === false || strpos($line, ':') === false) continue;

            // separa por los puntos
            $parts = preg_split('/\.{3,}:\s*/', $line, 2);
            if (!$parts || count($parts) < 2) continue;

            $k = trim($parts[0]);
            $v = trim($parts[1]);

            if ($k !== '') $data[$k] = $v;
        }

        return $data;
    }
}
