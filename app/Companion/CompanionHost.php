<?php
declare(strict_types=1);

namespace App\Companion;

use InvalidArgumentException;

/**
 * Resolves the Asterisk host written into the generated companion config.toml.
 *
 * An explicitly configured host always wins: SERVER_ADDR is a transport detail
 * of the current HTTP request and may point at a different machine when a node
 * has more than one address or sits behind a proxy.
 */
final class CompanionHost
{
    public static function resolve(string $configured, string $serverAddress): string
    {
        $explicit = trim($configured);

        if ($explicit !== '') {
            if (!self::isValid($explicit)) {
                throw new InvalidArgumentException('Invalid companion IAX host: rejected by validation');
            }

            return $explicit;
        }

        $host = trim($serverAddress);
        if ($host === '' || $host === '127.0.0.1' || $host === '::1') {
            $detected = trim((string) gethostbyname((string) gethostname()));
            $host = ($detected !== '' && $detected !== '127.0.0.1') ? $detected : '127.0.0.1';
        }

        if (!self::isValid($host)) {
            throw new InvalidArgumentException('Invalid detected IAX host: rejected by validation');
        }

        return $host;
    }

    public static function isValid(string $host): bool
    {
        if ($host === '' || $host !== trim($host) || strlen($host) > 253) {
            return false;
        }

        // Hostnames, IPv4 and bracketed IPv6 only. Quotes, whitespace,
        // slashes and control characters would break the generated TOML.
        return preg_match('/^[A-Za-z0-9._:\-\[\]]+$/', $host) === 1;
    }
}
