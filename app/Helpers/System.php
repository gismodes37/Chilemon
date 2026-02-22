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

        return $info;
    }
}