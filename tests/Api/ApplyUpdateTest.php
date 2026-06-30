<?php
declare(strict_types=1);

namespace Tests\Api;

use App\Auth\Auth;
use App\Core\RateLimiter;
use App\Services\UpdateService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for POST /api/apply-update.php.
 *
 * Verifies JSON response shape, admin guard, CSRF validation,
 * and rate limiting behaviors. Since the endpoint calls exit(),
 * we test the underlying components that implement each contract.
 *
 * Response contract:
 *   200 → { success, action, message, stashed, commit }
 *   400 → { success: false, error: "Bad Request (CSRF)" }
 *   403 → { success: false, error: "Forbidden: ..." }
 *   429 → { success: false, error: "..." }
 *   500 → { success: false, error: "..." }
 */
#[CoversNothing]
final class ApplyUpdateTest extends TestCase
{
    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION;
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    // ---------------------------------------------------------------
    //  Response shape (service layer)
    // ---------------------------------------------------------------

    #[Test]
    public function applyResponseHasRequiredFields(): void
    {
        // Parse mock git-pull output to verify response field contract
        $output = "Updating abc123..def456\n"
                . "Fast-forward\n"
                . " 3 files changed, 42 insertions(+), 5 deletions(-)\n"
                . "STASHED:0\n";
        $result = UpdateService::parsePullOutput($output);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('stashed', $result);
        $this->assertArrayHasKey('commit', $result);

        $this->assertTrue($result['success']);
        $this->assertSame('apply-update', $result['action']);
        $this->assertSame('def456', $result['commit']);
    }

    #[Test]
    public function applyResponseAlreadyUpToDate(): void
    {
        $output = "Already up to date.\nSTASHED:0\n";
        $result = UpdateService::parsePullOutput($output);

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['commit']);
        $this->assertStringContainsString('Already up to date', $result['message']);
    }

    #[Test]
    public function applyResponseOnMergeConflict(): void
    {
        $output = "error: could not apply...\nCONFLICT\nSTASHED:1\n";
        $result = UpdateService::parsePullOutput($output);

        $this->assertFalse($result['success']);
        $this->assertSame('Git pull failed.', $result['message']);
    }

    #[Test]
    public function applyResponseOnEmptyInput(): void
    {
        $result = UpdateService::parsePullOutput('');

        $this->assertTrue($result['success']);
        $this->assertSame('', $result['commit']);
        $this->assertStringContainsString('Already up to date', $result['message']);
    }

    // ---------------------------------------------------------------
    //  Admin guard (endpoint behavior)
    // ---------------------------------------------------------------

    #[Test]
    public function applyEndpointRequiresAdmin(): void
    {
        // Without a session, isAdmin must be false
        $_SESSION = [];
        Auth::startSession();

        // The endpoint calls Auth::requireAdmin() which checks isAdmin()
        $this->assertFalse(Auth::isAdmin());
    }

    #[Test]
    public function applyEndpointRejectsNonAdminSession(): void
    {
        // With a non-admin session, isAdmin must be false
        $_SESSION = [
            'user_logged_in' => true,
            'user_role'      => 'user',
            'user_id'        => 2,
        ];

        $this->assertFalse(Auth::isAdmin());
    }

    // ---------------------------------------------------------------
    //  CSRF validation (endpoint behavior)
    // ---------------------------------------------------------------

    #[Test]
    public function invalidCsrfTokenIsRejected(): void
    {
        // Generate a real token first to ensure there's one in session
        Auth::csrfToken();

        // Then validate a different token — must fail
        $this->assertFalse(Auth::validateCsrf('this-is-not-the-correct-token'));
    }

    #[Test]
    public function emptyCsrfTokenIsRejected(): void
    {
        $this->assertFalse(Auth::validateCsrf(''));
    }

    // ---------------------------------------------------------------
    //  Rate limiting (endpoint behavior)
    // ---------------------------------------------------------------

    #[Test]
    public function applyRateLimitPassesUnderThreshold(): void
    {
        $this->expectNotToPerformAssertions();
        RateLimiter::check('test-apply-update-' . uniqid('', true), 5, 60);
    }
}
