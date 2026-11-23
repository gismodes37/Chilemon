<?php
declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the git verbs in the sudo wrapper.
 */
final class UpdateWrapperTest extends TestCase
{
    private function wrapperSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/bin/chilemon-rpt');
    }

    #[Test]
    public function gitPullReportsOnlyThePullExitCode(): void
    {
        $source = $this->wrapperSource();

        self::assertStringContainsString('PULL_RC=$?', $source);
        self::assertStringContainsString('echo "STASHED:$PULL_RC"', $source);
        self::assertStringNotContainsString('echo "STASHED:$?"', $source);
    }

    #[Test]
    public function gitVerbsRegisterTheInstallPathAsSafeDirectory(): void
    {
        $source = $this->wrapperSource();

        self::assertStringContainsString('ensure_safe_directory', $source);
        self::assertStringContainsString('safe.directory', $source);
    }
}
