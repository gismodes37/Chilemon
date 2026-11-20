<?php
declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for installer privileges and filesystem exposure.
 */
final class ServicePrivilegesTest extends TestCase
{
    private const ROOT = dirname(__DIR__, 2);

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $relativePath);
    }

    #[Test]
    public function webrtcServiceDoesNotRunAsRoot(): void
    {
        $unit = $this->source('install/chilemon-webrtc.service');

        self::assertStringNotContainsString('User=root', $unit);
        self::assertStringNotContainsString('Group=root', $unit);
        self::assertStringContainsString('User=chilemon', $unit);
    }

    #[Test]
    public function webrtcServiceDoesNotRequestUnneededCapabilities(): void
    {
        $unit = $this->source('install/chilemon-webrtc.service');

        self::assertStringNotContainsString('AmbientCapabilities', $unit);
        self::assertStringNotContainsString('CapabilityBoundingSet', $unit);
    }

    #[Test]
    public function installerCreatesUnprivilegedServiceUser(): void
    {
        foreach (['install/install_chilemon.sh', 'install/install_webrtc.sh'] as $file) {
            $source = $this->source($file);
            self::assertStringContainsString('useradd', $source, "{$file} must create the service user");
            self::assertStringContainsString('chilemon', $source, "{$file} must reference the service user");
        }
    }

    #[Test]
    public function installerDoesNotMakeWholeRepoGroupWritable(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        self::assertStringNotContainsString('chmod -R g+w "$REPO_DIR"', $source);
        self::assertStringNotContainsString('chmod g+s', $source);
    }

    #[Test]
    public function installerRestrictsWriteToRuntimeDirectories(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        foreach (['data', 'logs', 'backups'] as $dir) {
            self::assertStringContainsString($dir, $source, "Runtime directory {$dir} must be configured");
        }
        self::assertStringContainsString('chmod 770', $source);
    }
}
