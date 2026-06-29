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
 * Integration tests for GET /api/check-update.php.
 *
 * Verifies JSON response shape, auth guard, and rate limiting
 * behaviors. Since the endpoint calls exit(), we test the
 * underlying components that implement each contract boundary.
 *
 * Response contract:
 *   200 → { ok, update_available, local_commit, remote_commit, summary }
 *   401 → { ok: false, error: "Unauthorized" }
 *   429 → { ok: false, error: "..." }
 */
#[CoversNothing]
final class CheckUpdateTest extends TestCase
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
    public function checkResponseHasRequiredFields(): void
    {
        // Parse mock git-compare output to verify response field contract
        $output  = "LOCAL:abc123def456abc123def456abc123def456abc1\n"
                 . "REMOTE:def456abc123def456abc123def456abc123def4\n"
                 . "SUMMARY:3 commits: Fix PTT timeout, Update README\n";
        $result  = UpdateService::parseCheckOutput($output);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('update_available', $result);
        $this->assertArrayHasKey('local_commit', $result);
        $this->assertArrayHasKey('remote_commit', $result);
        $this->assertArrayHasKey('summary', $result);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['update_available']);
        $this->assertSame('abc123def456abc123def456abc123def456abc1', $result['local_commit']);
        $this->assertSame('def456abc123def456abc123def456abc123def4', $result['remote_commit']);
        $this->assertSame('3 commits: Fix PTT timeout, Update README', $result['summary']);
    }

    #[Test]
    public function checkResponseWhenNoUpdateAvailable(): void
    {
        // Same local/remote hash → update_available = false
        $output = "LOCAL:abc123\nREMOTE:abc123\nSUMMARY:up-to-date\n";
        $result = UpdateService::parseCheckOutput($output);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['update_available']);
        $this->assertSame('abc123', $result['local_commit']);
        $this->assertSame('abc123', $result['remote_commit']);
    }

    // ---------------------------------------------------------------
    //  Auth guard (endpoint behavior)
    // ---------------------------------------------------------------

    #[Test]
    public function endpointRequiresAuth(): void
    {
        // Without a session, isLoggedIn must be false
        $_SESSION = [];
        Auth::startSession();
        $this->assertFalse(Auth::isLoggedIn());
    }

    // ---------------------------------------------------------------
    //  Rate limiting (endpoint behavior)
    // ---------------------------------------------------------------

    #[Test]
    public function checkRateLimitPassesUnderThreshold(): void
    {
        // RateLimiter::check should not throw within limits
        // Use a unique key to avoid collision with other tests
        $this->expectNotToPerformAssertions();
        RateLimiter::check('test-check-update-' . uniqid('', true), 30, 60);
    }
}
