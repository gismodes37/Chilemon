<?php
declare(strict_types=1);

namespace Tests\Companion;

use App\Companion\CompanionHost;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The companion must dial the explicitly configured IAX host, never a
 * server-derived address that can point at a different machine.
 */
final class CompanionHostTest extends TestCase
{
    #[Test]
    public function explicitHostWinsOverServerAddress(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::resolve('192.168.0.110', '192.168.0.108')
        );
    }

    #[Test]
    public function explicitHostnameIsAccepted(): void
    {
        self::assertSame(
            'node494780.local',
            CompanionHost::resolve('node494780.local', '192.168.0.108')
        );
    }

    #[Test]
    public function serverAddressIsUsedWhenNoExplicitHost(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::resolve('', '192.168.0.110')
        );
    }

    #[Test]
    public function loopbackServerAddressFallsBackToDetectedHost(): void
    {
        $result = CompanionHost::resolve('', '127.0.0.1');

        self::assertNotSame('127.0.0.1', $result);
        self::assertTrue(CompanionHost::isValid($result));
    }

    #[Test]
    public function explicitLoopbackIsHonouredForLocalTesting(): void
    {
        self::assertSame('127.0.0.1', CompanionHost::resolve('127.0.0.1', '192.168.0.108'));
    }

    #[Test]
    public function tomlInjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CompanionHost::resolve("192.168.0.110\"\n[peer]\npassword = \"x", '192.168.0.108');
    }

    #[Test]
    public function whitespaceAndQuotesAreRejected(): void
    {
        foreach ([' 192.168.0.110 ', '192.168.0.110"', "192.168.0.110\n"] as $host) {
            self::assertFalse(CompanionHost::isValid($host), "Host must be rejected: {$host}");
        }
    }

    #[Test]
    public function isValidAcceptsHostnameIpAndIpv6(): void
    {
        self::assertTrue(CompanionHost::isValid('192.168.0.110'));
        self::assertTrue(CompanionHost::isValid('node494780.local'));
        self::assertTrue(CompanionHost::isValid('[::1]'));
        self::assertFalse(CompanionHost::isValid(''));
        self::assertFalse(CompanionHost::isValid('bad host'));
        self::assertFalse(CompanionHost::isValid("bad\nhost"));
    }
}
