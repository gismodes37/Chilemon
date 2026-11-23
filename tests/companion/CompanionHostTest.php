<?php
declare(strict_types=1);

namespace Tests\Companion;

use App\Companion\CompanionHost;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The installer must work for any address used to reach a running node.
 * The host is discovered from the download request, never pinned in config.
 */
final class CompanionHostTest extends TestCase
{
    #[Test]
    public function browserFacingHostWinsOverServerAddress(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest([
                'HTTP_HOST' => '192.168.0.110',
                'SERVER_ADDR' => '192.168.0.108',
            ])
        );
    }

    #[Test]
    public function portIsStrippedFromTheBrowserFacingHost(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest([
                'HTTP_HOST' => '192.168.0.110:8443',
                'SERVER_ADDR' => '192.168.0.108',
            ])
        );
    }

    #[Test]
    public function hostnameFromBrowserIsAccepted(): void
    {
        self::assertSame(
            'node494780.local',
            CompanionHost::fromRequest([
                'HTTP_HOST' => 'node494780.local:443',
                'SERVER_ADDR' => '192.168.0.108',
            ])
        );
    }

    #[Test]
    public function serverAddressIsUsedWhenNoHostHeader(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest(['SERVER_ADDR' => '192.168.0.110'])
        );
    }

    #[Test]
    public function tomlInjectionInHostHeaderFallsBackToServerAddress(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest([
                'HTTP_HOST' => "192.168.0.108\"\n[peer]\npassword = \"x",
                'SERVER_ADDR' => '192.168.0.110',
            ])
        );
    }

    #[Test]
    public function invalidPortInHostHeaderFallsBackToServerAddress(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest([
                'HTTP_HOST' => '192.168.0.108:not-a-port',
                'SERVER_ADDR' => '192.168.0.110',
            ])
        );
    }

    #[Test]
    public function loopbackHostHeaderDoesNotHideTheLanAddress(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest([
                'HTTP_HOST' => 'localhost',
                'SERVER_ADDR' => '192.168.0.110',
            ])
        );
    }

    #[Test]
    public function bracketedIpv6WithPortIsAccepted(): void
    {
        self::assertSame(
            '[2001:db8::1]',
            CompanionHost::fromRequest([
                'HTTP_HOST' => '[2001:db8::1]:8443',
                'SERVER_ADDR' => '192.168.0.108',
            ])
        );
    }

    #[Test]
    public function bracketedLoopbackIsSkippedToo(): void
    {
        self::assertSame(
            '192.168.0.110',
            CompanionHost::fromRequest([
                'HTTP_HOST' => '[::1]:8443',
                'SERVER_ADDR' => '192.168.0.110',
            ])
        );
    }

    #[Test]
    public function firstValidCandidateWins(): void
    {
        self::assertSame(
            '10.0.0.5',
            CompanionHost::resolve(['bad host', '', '10.0.0.5', '10.0.0.6'])
        );
    }

    #[Test]
    public function noValidCandidateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CompanionHost::resolve(['', 'bad host']);
    }

    #[Test]
    public function isValidAcceptsHostnameIpAndIpv6Only(): void
    {
        self::assertTrue(CompanionHost::isValid('192.168.0.110'));
        self::assertTrue(CompanionHost::isValid('node494780.local'));
        self::assertTrue(CompanionHost::isValid('[::1]'));
        self::assertFalse(CompanionHost::isValid(''));
        self::assertFalse(CompanionHost::isValid('bad host'));
        self::assertFalse(CompanionHost::isValid("bad\nhost"));
        self::assertFalse(CompanionHost::isValid('192.168.0.110"'));
    }
}
