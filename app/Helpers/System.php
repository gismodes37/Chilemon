<?php
declare(strict_types=1);

namespace App\Helpers;

final class System
{
    public static function getCpuTemperature(): float
    {
        if (function_exists('shell_exec')) {
            $temp = shell_exec('vcgencmd measure_temp 2>/dev/null');
            if ($temp && preg_match('/temp=([\d.]+)/', $temp, $m)) {
                return (float) $m[1];
            }

            $temp = shell_exec('cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null');
            if ($temp !== null && trim($temp) !== '') {
                return ((float) $temp) / 1000;
            }
        }

        // fallback dev
        return (float) random_int(40, 55);
    }

    public static function getTemperatureStatus(float $celsius): string
    {
        if ($celsius < 50) return 'Temperatura normal';
        if ($celsius < 70) return 'Temperatura moderada';
        return 'Temperatura alta';
    }

    /** @return array{ipv4: string[], ipv6: string[]} */
    public static function getIpLists(): array
    {
        $ipv4 = [];
        $ipv6 = [];

        if (!function_exists('shell_exec')) {
            return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
        }

        $out = trim((string) shell_exec('hostname -I 2>/dev/null'));
        if ($out === '') {
            return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
        }

        foreach (preg_split('/\s+/', $out) as $ip) {
            if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip)) $ipv4[] = $ip;
            elseif (strpos($ip, ':') !== false) $ipv6[] = $ip;
        }

        return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
    }

    private static function readProcFile(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }

        $contents = @file_get_contents($path);
        return $contents === false ? '' : $contents;
    }

    /** @return array{model: string, board: string, cores: int} */
    public static function getCpuInfo(): array
    {
        $raw = self::readProcFile('/proc/cpuinfo');
        $model = 'Desconocido';
        $board = '';
        $cores = 0;

        if ($raw !== '') {
            if (preg_match('/^model name\s*:\s*(.+)$/mi', $raw, $matches) === 1) {
                $model = trim($matches[1]);
            }
            if (preg_match('/^Model\s*:\s*(.+)$/mi', $raw, $matches) === 1) {
                $board = trim($matches[1]);
            }
            $cores = preg_match_all('/^processor\s*:/mi', $raw);
        }

        return ['model' => $model, 'board' => $board, 'cores' => $cores];
    }

    /** @return array{total: string, used: string, available: string, percent: int} */
    public static function getMemoryInfo(): array
    {
        $empty = ['total' => '—', 'used' => '—', 'available' => '—', 'percent' => 0];
        $raw = self::readProcFile('/proc/meminfo');
        $totalKb = self::meminfoValue($raw, 'MemTotal');
        $availableKb = self::meminfoValue($raw, 'MemAvailable');

        if ($totalKb <= 0 || $availableKb <= 0) {
            return $empty;
        }

        $usedKb = max(0, $totalKb - $availableKb);

        return [
            'total'     => self::formatMemory($totalKb * 1024),
            'used'      => self::formatMemory($usedKb * 1024),
            'available' => self::formatMemory($availableKb * 1024),
            'percent'   => (int) round(($usedKb / $totalKb) * 100),
        ];
    }

    private static function meminfoValue(string $raw, string $key): int
    {
        $pattern = '/^' . preg_quote($key, '/') . ':\s*(\d+)\s*kB/mi';
        return preg_match($pattern, $raw, $matches) === 1 ? (int)$matches[1] : 0;
    }

    private static function formatMemory(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, '.', '') . ' GiB';
        }

        return number_format($bytes / 1048576, 0, '.', '') . ' MiB';
    }

    public static function getUptime(): string
    {
        $raw = self::readProcFile('/proc/uptime');
        if (preg_match('/^(\d+(?:\.\d+)?)/', $raw, $matches) !== 1) {
            return '—';
        }

        $seconds = (int)$matches[1];
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    }

    /** @return array<string, mixed> */
    public static function getSystemInfo(): array
    {
        $info = [
            'lan_ip'          => '127.0.0.1',
            'web_port'        => (int)($_SERVER['SERVER_PORT'] ?? 80),
            'hostname'        => gethostname() ?: 'localhost',
            'php_version'     => phpversion() ?: 'unknown',
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido',
            'timezone'        => date_default_timezone_get(),
        ];

        $ips = self::getIpLists();
        if (!empty($ips['ipv4'])) {
            $info['lan_ip'] = $ips['ipv4'][0];
        }

        $c = self::getCpuTemperature();
        $info['cpu_temp_c'] = $c;
        $info['cpu_temp_f'] = round(($c * 9 / 5) + 32, 1);

        $cpu = self::getCpuInfo();
        $info['cpu_model'] = $cpu['model'];
        $info['cpu_board'] = $cpu['board'];
        $info['cpu_cores'] = $cpu['cores'];

        $memory = self::getMemoryInfo();
        $info['mem_total'] = $memory['total'];
        $info['mem_used'] = $memory['used'];
        $info['mem_available'] = $memory['available'];
        $info['mem_used_percent'] = $memory['percent'];

        $info['uptime'] = self::getUptime();
        $info['kernel'] = php_uname('r');

        return $info;
    }
}