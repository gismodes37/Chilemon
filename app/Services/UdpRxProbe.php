<?php
declare(strict_types=1);

namespace App\Services;

/**
 * UdpRxProbe — detects incoming audio from the kernel UDP counters.
 *
 * On this node app_rpt exposes no working RX flag: "Signal on input" stays
 * NO and RPT_RXKEYED never fires, because rxchannel is Local/pseudo with
 * no radio attached. Incoming audio does arrive over IAX2, so the UDP
 * packet rate at /proc/net/snmp separates the cases (measured on 61916):
 *
 *   idle / keepalive ....... ~1 pkt/s
 *   noise bursts ........... 20-80 pkt/s for < 1 s -> <= ~5 pkt/s averaged
 *   voice .................. 72-82 pkt/s while the caller keys
 *
 * The probe keeps a small state file (counter + timestamp + last verdict)
 * and compares the mean rate since the previous poll against a threshold.
 * Every failure resolves to false: the badge must never break the nodes
 * endpoint.
 */
final class UdpRxProbe
{
    /** Mean packet rate (pkt/s) required to classify a window as voice. */
    public const DEFAULT_THRESHOLD = 8.0;

    /** Windows shorter than this reuse the stored verdict instead of a rate. */
    private const MIN_WINDOW_SECONDS = 1.0;

    private string $snmpPath;
    private string $statePath;
    private float $threshold;

    public function __construct(
        string $snmpPath = '/proc/net/snmp',
        string $statePath = '',
        float $threshold = self::DEFAULT_THRESHOLD
    ) {
        $this->snmpPath  = $snmpPath;
        $this->statePath = $statePath !== '' ? $statePath : self::defaultStatePath();
        $this->threshold = $threshold;
    }

    /**
     * True when the UDP receive rate since the previous poll looks like voice.
     *
     * @param float|null $now Injectable clock for tests; null = microtime.
     */
    public function isRxActive(?float $now = null): bool
    {
        try {
            $raw = @file_get_contents($this->snmpPath);
            if ($raw === false) {
                return false;
            }

            $current = self::parseInDatagrams($raw);
            if ($current === null) {
                return false;
            }

            $now  ??= microtime(true);
            $state = $this->readState();

            if ($state === null) {
                // First sight (or corrupt state): anchor the counter, no verdict.
                $this->writeState($current, $now, false);
                return false;
            }

            $elapsed = $now - $state['time'];
            if ($elapsed < self::MIN_WINDOW_SECONDS) {
                return $state['rx'];
            }

            $rate = self::rate($state['count'], $state['time'], $current, $now);
            $rx   = $rate !== null && $rate >= $this->threshold;
            $this->writeState($current, $now, $rx);

            return $rx;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * InDatagrams value from the second "Udp:" line of /proc/net/snmp,
     * or null when the section is missing or malformed.
     */
    public static function parseInDatagrams(string $raw): ?int
    {
        $seen = 0;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (!str_starts_with($line, 'Udp:')) {
                continue;
            }
            $seen++;
            if ($seen < 2) {
                continue; // first line is the header row, second carries values
            }
            $fields = preg_split('/\s+/', trim($line));
            $value  = $fields[1] ?? '';
            return ctype_digit($value) ? (int) $value : null;
        }

        return null;
    }

    /**
     * Packet rate between two counter samples, or null when undefined
     * (missing sample, counter reset, or a window that did not advance).
     */
    public static function rate(
        ?int $previousCount,
        float $previousTime,
        int $currentCount,
        float $currentTime
    ): ?float {
        if ($previousCount === null || $previousTime <= 0.0 || $currentTime <= $previousTime) {
            return null;
        }

        $delta = $currentCount - $previousCount;
        if ($delta < 0) {
            return null; // counter reset (kernel restart)
        }

        return $delta / ($currentTime - $previousTime);
    }

    private static function defaultStatePath(): string
    {
        $dir = defined('DATA_PATH') ? DATA_PATH : sys_get_temp_dir();

        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'udp_rx_state.json';
    }

    /**
     * @return array{count:int, time:float, rx:bool}|null
     */
    private function readState(): ?array
    {
        $raw = @file_get_contents($this->statePath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)
            || !isset($data['count'], $data['time'], $data['rx'])
            || !is_numeric($data['count'])
            || !is_numeric($data['time'])
            || !is_bool($data['rx'])
        ) {
            return null;
        }

        return [
            'count' => (int) $data['count'],
            'time'  => (float) $data['time'],
            'rx'    => $data['rx'],
        ];
    }

    private function writeState(int $count, float $time, bool $rx): void
    {
        $json = json_encode(['count' => $count, 'time' => $time, 'rx' => $rx]);
        if ($json === false) {
            return;
        }

        @file_put_contents($this->statePath, $json, LOCK_EX);
    }
}
