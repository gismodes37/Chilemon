<?php
declare(strict_types=1);

namespace App\Companion;

use InvalidArgumentException;

/**
 * Discovers the Asterisk host advertised to the Windows Companion.
 *
 * The installer is a public download: the address must come from how the node
 * was reached for this request, never from a per-installation pinned value.
 * A literal IP in the browser-facing Host header wins. A hostname is not safe
 * for IAX2 on a multi-homed node: DNS can point to a secondary interface while
 * Asterisk answers from its primary one, so the client drops every reply. In
 * that case the node's own detected LAN address is used instead.
 */
final class CompanionHost
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @param array<string, mixed> $server PHP's $_SERVER for the download request
     * @param string|null $detectedIp Overrides LAN detection; used by tests
     */
    public static function fromRequest(array $server, ?string $detectedIp = null): string
    {
        $detected = trim((string) ($detectedIp ?? gethostbyname((string) gethostname())));
        $hostHeader = self::normalize((string) ($server['HTTP_HOST'] ?? ''));
        $serverAddress = self::normalize((string) ($server['SERVER_ADDR'] ?? ''));
        $hostIsLoopback = $hostHeader !== null && in_array(strtolower($hostHeader), self::LOOPBACK, true);

        // A hostname may resolve to a secondary interface on the same node;
        // Asterisk replies from its primary one and IAX2 drops those packets.
        // Prefer the detected LAN IP whenever the browser used a hostname.
        if ($hostHeader !== null && !$hostIsLoopback && !self::isIpLiteral($hostHeader)) {
            if ($detected !== '' && self::isValid($detected) && !in_array(strtolower($detected), self::LOOPBACK, true)) {
                return $detected;
            }
        }

        return self::resolve([
            $hostIsLoopback ? '' : ($hostHeader ?? ''),
            $serverAddress ?? '',
            $detected,
        ]);
    }

    /**
     * Returns the first routable IP literal, then the first hostname, falling
     * back to loopback only when nothing else is available. Invalid candidates
     * are skipped, so a malformed header degrades to the next source instead of
     * breaking the download.
     *
     * @param list<string> $candidates
     */
    public static function resolve(array $candidates): string
    {
        $loopback = null;
        $hostname = null;

        foreach ($candidates as $candidate) {
            $host = self::normalize((string) $candidate);
            if ($host === null) {
                continue;
            }
            if (in_array(strtolower($host), self::LOOPBACK, true)) {
                $loopback ??= $host;
                continue;
            }
            if (!self::isIpLiteral($host)) {
                $hostname ??= $host;
                continue;
            }

            return $host;
        }

        if ($hostname !== null) {
            return $hostname;
        }

        if ($loopback !== null) {
            return $loopback;
        }

        throw new InvalidArgumentException('No valid IAX host could be determined from the request.');
    }

    public static function isValid(string $host): bool
    {
        if ($host === '' || $host !== trim($host) || strlen($host) > 253) {
            return false;
        }

        // Hostnames, IPv4 and bracketed IPv6 only. Quotes, whitespace, slashes
        // and control characters would break the generated TOML.
        return preg_match('/^[A-Za-z0-9._:\-\[\]]+$/', $host) === 1;
    }

    private static function isIpLiteral(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private static function normalize(string $candidate): ?string
    {
        $value = trim($candidate);
        if ($value === '') {
            return null;
        }

        // Bracketed IPv6 may carry a port: [::1]:8443.
        if ($value[0] === '[') {
            $end = strpos($value, ']');
            if ($end === false) {
                return null;
            }
            $host = substr($value, 0, $end + 1);
            $rest = trim(substr($value, $end + 1));
            if ($rest !== '' && !(str_starts_with($rest, ':') && ctype_digit(substr($rest, 1)))) {
                return null;
            }

            return self::isValid($host) ? $host : null;
        }

        // An unbracketed value with several colons is ambiguous with :port.
        if (substr_count($value, ':') > 1) {
            return null;
        }

        if (str_contains($value, ':')) {
            [$host, $port] = explode(':', $value, 2);
            if ($port !== '' && !ctype_digit($port)) {
                return null;
            }
            $value = $host;
        }

        return self::isValid($value) ? $value : null;
    }
}
