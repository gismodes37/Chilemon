<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\SystemController;
use App\Services\AslRptService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the sudo wrapper hardening.
 *
 * The dashboard must not be able to reach poweroff or service restarts:
 * those verbs used to be callable through the NOPASSWD sudo wrapper.
 */
final class DestructiveActionsTest extends TestCase
{
    private const ROOT = dirname(__DIR__, 2);

    private const DESTRUCTIVE_VERBS = [
        'sys-poweroff',
        'sys-restart-asterisk',
        'sys-restart-apache',
    ];

    private const UI_ACTIONS = [
        'poweroff',
        'restart-asterisk',
        'restart-apache',
    ];

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $relativePath);
    }

    #[Test]
    public function wrapperDoesNotExposeDestructiveVerbs(): void
    {
        $source = $this->source('bin/chilemon-rpt');

        foreach (self::DESTRUCTIVE_VERBS as $verb) {
            self::assertStringNotContainsString(
                $verb,
                $source,
                "Wrapper still exposes {$verb}"
            );
        }
    }

    #[Test]
    public function generatedWrapperDoesNotExposeDestructiveVerbs(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        foreach (self::DESTRUCTIVE_VERBS as $verb) {
            self::assertStringNotContainsString(
                $verb,
                $source,
                "Installer still writes {$verb} into the generated wrapper"
            );
        }
    }

    #[Test]
    public function wrapperValidatesBothNodeArgumentsAsDigits(): void
    {
        $source = $this->source('bin/chilemon-rpt');

        self::assertMatchesRegularExpression(
            '/LOCAL=.*\[0-9\]/',
            $source,
            'Local node must be validated as digits before reaching asterisk -rx'
        );
        self::assertMatchesRegularExpression(
            '/REMOTE=.*\[0-9\]/',
            $source,
            'Remote node must be validated as digits before reaching asterisk -rx'
        );
        self::assertStringContainsString('exit 1', $source);
    }

    #[Test]
    public function serviceLayerNoLongerExposesDestructiveActions(): void
    {
        foreach (['powerOff', 'restartAsterisk', 'restartApache'] as $method) {
            self::assertFalse(
                method_exists(AslRptService::class, $method),
                "AslRptService::{$method}() must be removed"
            );
        }
    }

    #[Test]
    public function systemControllerRejectsDestructiveActions(): void
    {
        $controller = new SystemController();

        foreach (self::UI_ACTIONS as $action) {
            $result = $controller->execute($action);
            self::assertFalse($result['success'], "SystemController still accepts {$action}");
        }
    }

    #[Test]
    public function dashboardDoesNotRenderDestructiveButtons(): void
    {
        $source = $this->source('public/views/dashboard.php');

        foreach (['btn-power-node', 'btn-restart-asterisk', 'btn-restart-apache'] as $id) {
            self::assertStringNotContainsString($id, $source, "Dashboard still renders {$id}");
        }
    }

    #[Test]
    public function sudoersGrantsRootOnly(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        self::assertStringContainsString('ALL=(root) NOPASSWD', $source);
        self::assertStringNotContainsString('ALL=(ALL) NOPASSWD', $source);
    }

    #[Test]
    public function installerInstallsTheCanonicalWrapper(): void
    {
        $source = $this->source('install/install_chilemon.sh');

        self::assertStringContainsString(
            'bin/chilemon-rpt',
            $source,
            'Installer must copy the maintained wrapper instead of writing an inline copy'
        );
    }
}
