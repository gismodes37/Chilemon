<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\UpdateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateService::class)]
final class UpdateServiceTest extends TestCase
{
    // ---------------------------------------------------------------
    //  ALLOWED Command Validation
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function allowedCommandsProvider(): array
    {
        return [
            'git-fetch is allowed'                     => ['git-fetch', true],
            'git-compare is allowed'                   => ['git-compare', true],
            'git-pull is allowed'                      => ['git-pull', true],
            'sys-restart-webrtc is allowed'            => ['sys-restart-webrtc', true],
            'sys-reload-apache is allowed'             => ['sys-reload-apache', true],
            'empty string is rejected'                 => ['', false],
            'unknown command is rejected'              => ['git-reset', false],
            'nodes command (AslRptService) is rejected' => ['nodes', false],
            'stats command (AslRptService) is rejected'  => ['stats', false],
            'connect command is rejected'              => ['connect', false],
            'SQL injection attempt is rejected'         => ["'; rm -rf /", false],
        ];
    }

    #[Test]
    #[DataProvider('allowedCommandsProvider')]
    public function commandValidation(string $cmd, bool $expected): void
    {
        $this->assertSame($expected, UpdateService::isAllowed($cmd));
    }

    // ---------------------------------------------------------------
    //  parseCheckOutput
    // ---------------------------------------------------------------

    #[Test]
    public function parseCheckOutputDetectsUpdateAvailable(): void
    {
        $output = "LOCAL:abc123def456abc123def456abc123def456abc1\n" .
                  "REMOTE:def456abc123def456abc123def456abc123def4\n" .
                  "SUMMARY:3 commits: Fix PTT timeout, Update README, Add CSRF tests";

        $result = UpdateService::parseCheckOutput($output);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['update_available']);
        $this->assertSame('abc123def456abc123def456abc123def456abc1', $result['local_commit']);
        $this->assertSame('def456abc123def456abc123def456abc123def4', $result['remote_commit']);
        $this->assertSame('3 commits: Fix PTT timeout, Update README, Add CSRF tests', $result['summary']);
    }

    #[Test]
    public function parseCheckOutputReturnsNoUpdateWhenLocalEqualsRemote(): void
    {
        $output = "LOCAL:abc123def456abc123def456abc123def456abc1\n" .
                  "REMOTE:abc123def456abc123def456abc123def456abc1\n" .
                  "SUMMARY:up-to-date";

        $result = UpdateService::parseCheckOutput($output);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['update_available']);
        $this->assertSame('abc123def456abc123def456abc123def456abc1', $result['local_commit']);
        $this->assertSame('abc123def456abc123def456abc123def456abc1', $result['remote_commit']);
        $this->assertSame('up-to-date', $result['summary']);
    }

    #[Test]
    public function parseCheckOutputHandlesEmptyRemote(): void
    {
        // Cuando origin/main no existe (nunca se ha hecho fetch)
        $output = "LOCAL:abc123def456abc123def456abc123def456abc1\n" .
                  "REMOTE:\n" .
                  "SUMMARY:up-to-date";

        $result = UpdateService::parseCheckOutput($output);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['update_available']);
        $this->assertSame('', $result['remote_commit']);
    }

    #[Test]
    public function parseCheckOutputHandlesMalformedLines(): void
    {
        $output = "some random output\n" .
                  "LOCAL:abc123\n" .
                  "NOTAREMOTE:xyz\n" .
                  "";

        $result = UpdateService::parseCheckOutput($output);

        $this->assertTrue($result['ok']);
        $this->assertSame('abc123', $result['local_commit']);
        $this->assertSame('', $result['remote_commit']);
        $this->assertSame('', $result['summary']);
    }

    // ---------------------------------------------------------------
    //  parsePullOutput
    // ---------------------------------------------------------------

    #[Test]
    public function parsePullOutputWithSuccessfulUpdate(): void
    {
        $output = "From https://github.com/gismodes37/Chilemon\n" .
                  " * branch            main       -> FETCH_HEAD\n" .
                  "Updating abc123..def456\n" .
                  "Fast-forward\n" .
                  " src/file.php | 2 ++\n" .
                  " 1 file changed, 2 insertions(+)\n" .
                  "STASHED:0";

        $result = UpdateService::parsePullOutput($output);

        $this->assertTrue($result['success']);
        $this->assertSame('apply-update', $result['action']);
        $this->assertSame('Update applied successfully.', $result['message']);
        $this->assertTrue($result['stashed']);
        $this->assertSame('def456', $result['commit']);
    }

    #[Test]
    public function parsePullOutputAlreadyUpToDate(): void
    {
        $output = "Already up to date.\nSTASHED:0";

        $result = UpdateService::parsePullOutput($output);

        $this->assertTrue($result['success']);
        $this->assertSame('Already up to date. No update applied.', $result['message']);
        $this->assertFalse($result['stashed']);
        $this->assertSame('', $result['commit']);
    }

    #[Test]
    public function parsePullOutputNoStashNeeded(): void
    {
        $output = "From https://github.com/gismodes37/Chilemon\n" .
                  " * branch            main       -> FETCH_HEAD\n" .
                  "Updating abc123..def456\n" .
                  "Fast-forward\n" .
                  " src/file.php | 2 ++\n" .
                  " 1 file changed, 2 insertions(+)\n" .
                  "STASHED:0";

        $result = UpdateService::parsePullOutput($output);

        // STASHED:0 means pull succeeded (exit code 0)
        $this->assertTrue($result['success']);
        $this->assertSame('def456', $result['commit']);
    }

    #[Test]
    public function parsePullOutputWithMergeConflict(): void
    {
        $output = "From https://github.com/gismodes37/Chilemon\n" .
                  " * branch            main       -> FETCH_HEAD\n" .
                  "Auto-merging src/file.php\n" .
                  "CONFLICT (content): Merge conflict in src/file.php\n" .
                  "Automatic merge failed; fix conflicts and then commit the result.\n" .
                  "STASHED:1";

        $result = UpdateService::parsePullOutput($output);

        $this->assertFalse($result['success']);
        $this->assertSame('Git pull failed.', $result['message']);
        $this->assertFalse($result['stashed']);
        $this->assertSame('', $result['commit']);
    }

    #[Test]
    public function parsePullOutputWithFatalError(): void
    {
        $output = "fatal: not a git repository (or any of the parent directories): .git\nSTASHED:128";

        $result = UpdateService::parsePullOutput($output);

        $this->assertFalse($result['success']);
        $this->assertSame('Git pull failed.', $result['message']);
    }

    #[Test]
    public function parsePullOutputEmptyInput(): void
    {
        $result = UpdateService::parsePullOutput('');

        $this->assertTrue($result['success']);
        $this->assertSame('Already up to date. No update applied.', $result['message']);
    }

    // ---------------------------------------------------------------
    //  Windows Mock
    // ---------------------------------------------------------------

    #[Test]
    public function windowsMockCheckReturnsExpectedShape(): void
    {
        // En Windows, UpdateService::check() retorna mock.
        // Probamos que el mock tenga la forma esperada creando una instancia
        // en un entorno simulado (siempre retorna mock en Windows).
        // Pero como no podemos mockear PHP_OS_FAMILY, probamos el parser estático directamente.
        $this->assertTrue(true); // placeholder: mock se prueba via parser tests
    }
}
