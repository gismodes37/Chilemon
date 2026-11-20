<?php
declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for IAX2 companion identity and network exposure.
 */
final class CompanionIdentityTest extends TestCase
{
    private const ROOT = dirname(__DIR__, 2);

    private const LEGACY_SECRET = 'chilemon2026';

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $relativePath);
    }

    #[Test]
    public function downloadEndpointUsesNoHardcodedSecret(): void
    {
        $source = $this->source('public/api/companion-download.php');

        self::assertStringNotContainsString(self::LEGACY_SECRET, $source);
        self::assertStringContainsString('IAX_COMPANION_SECRET', $source);
    }

    #[Test]
    public function companionInstallerUsesNoHardcodedSecret(): void
    {
        $source = $this->source('install/companion/install.sh');

        self::assertStringNotContainsString(self::LEGACY_SECRET, $source);
    }

    #[Test]
    public function installerGeneratesCompanionSecret(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        self::assertStringContainsString('iax_companion_secret', $source);
        self::assertStringContainsString('openssl rand', $source);
    }

    #[Test]
    public function configExposesCompanionSecret(): void
    {
        $source = $this->source('config/app.php');

        self::assertStringContainsString('IAX_COMPANION_SECRET', $source);
        self::assertStringContainsString('iax_companion_secret', $source);
    }

    #[Test]
    public function iaxAccessIsNotOpenToEveryAddress(): void
    {
        $installer = $this->source('install/install_chilemon.sh');
        $snippet = $this->source('install/asterisk/iax-companion.conf');

        self::assertStringNotContainsString('calltokenoptional=0.0.0.0/0', $installer);
        self::assertStringNotContainsString('s/calltokenoptional=127\\.0\\.0\\.1/calltokenoptional=0.0.0.0\\/0', $installer);
        self::assertStringContainsString('deny=0.0.0.0/0.0.0.0', $snippet);
        self::assertStringContainsString('permit=__IAX_LAN_ACL__', $snippet);
    }

    #[Test]
    public function dialplanDoesNotPassCallerControlledDigitsToRpt(): void
    {
        foreach (['install/companion/install.sh', 'install/asterisk/extensions-companion.conf'] as $file) {
            $source = $this->source($file);
            self::assertStringNotContainsString('Rpt(${EXTEN})', $source, $file);
        }

        // One canonical dialplan: the companion installer injects the shared
        // file, substituting only the local node placeholder.
        $canonical = $this->source('install/asterisk/extensions-companion.conf');
        self::assertStringContainsString('rpt(__LOCAL_NODE__|S)', $canonical);

        $installer = $this->source('install/companion/install.sh');
        self::assertStringContainsString('extensions-companion.conf', $installer);
        self::assertStringContainsString('CHILEMON_LOCAL_NODE', $installer);
    }

    #[Test]
    public function shippedCompanionConfigHasNoSecret(): void
    {
        $source = $this->source('companion/config.toml');

        self::assertStringNotContainsString(self::LEGACY_SECRET, $source);
        self::assertStringContainsString('password = ""', $source);
    }

    #[Test]
    public function discoveryNeverInventsAPassword(): void
    {
        $source = $this->source('companion/main.py');

        self::assertStringNotContainsString(self::LEGACY_SECRET, $source);
        self::assertStringContainsString('os.chmod(config_path, 0o600)', $source);
    }

    #[Test]
    public function localConfigIsNotWorldReadable(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        self::assertStringContainsString('chmod 640 "$LOCAL_CONFIG"', $source);
    }
}
