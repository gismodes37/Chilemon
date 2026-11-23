<?php
declare(strict_types=1);

namespace App\Companion;

use InvalidArgumentException;

/**
 * Discovers the Asterisk host advertised to the Windows Companion.
 *
 * The installer is a public download: the address must come from how the node
 * was reached for this request, never from a per-installation pinned value.
 * The browser-facing Host header is preferred because it is the address that
 * actually worked for the client; SERVER_ADDR is only a fallback because it
 * can reflect a different local interface on a multi-homed node.
 */
final class CompanionHost
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @param array<string, mixed> $server PHP's $_SERVER for the download request
     */
    public static function fromRequest(array $server): string
    {
        $detected = trim((string) gethostbyname((string) gethostname()));

        return self::resolve([
            (string) ($server['HTTP_HOST'] ?? ''),
            (string) ($server['SERVER_ADDR'] ?? ''),
            $detected,
        ]);
    }

    /**
     * Returns the first routable candidate, falling back to loopback only when
     * nothing else is available. Invalid candidates are skipped, so a malformed
     * header degrades to the next source instead of breaking the download.
     *
     * @param list<string> $candidates
     */
    public static function resolve(array $candidates): string
    {
        $loopback = null;

        foreach ($candidates as $candidate) {
            $host = self::normalize((string) $candidate);
            if ($host === null) {
                continue;
            }
            if (in_array(strtolower($host), self::LOOPBACK, true)) {
                $loopback ??= $host;
                continue;
            }

            return $host;
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
