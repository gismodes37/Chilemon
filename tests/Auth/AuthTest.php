<?php
declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\Auth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Auth::class)]
final class AuthTest extends TestCase
{
    /**
     * Store the original session state before each test.
     */
    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    // ---------------------------------------------------------------
    //  CSRF Token
    // ---------------------------------------------------------------

    #[Test]
    public function csrfTokenReturnsNonEmptyString(): void
    {
        $token = Auth::csrfToken();
        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    #[Test]
    public function validateCsrfWithValidToken(): void
    {
        $token = Auth::csrfToken();
        $this->assertTrue(Auth::validateCsrf($token));
    }

    #[Test]
    public function validateCsrfWithInvalidToken(): void
    {
        Auth::csrfToken(); // ensure a token is present in session
        $this->assertFalse(Auth::validateCsrf('this-is-not-the-correct-token'));
    }

    /**
     * The dashboard reads the CSRF token once from a static <meta> tag and
     * reuses it for every AJAX call. Rotating on each validation made the
     * second call fail with HTTP 400 (e.g. save favorite -> connect). The
     * token is per-session and is already rotated at login and logout.
     */
    #[Test]
    public function validateCsrfKeepsTokenStableForReuse(): void
    {
        $token = Auth::csrfToken();

        $this->assertTrue(Auth::validateCsrf($token));
        $this->assertSame($token, Auth::csrfToken(), 'CSRF token must not rotate on validation');
        $this->assertTrue(
            Auth::validateCsrf($token),
            'the same page token must remain valid for a second AJAX call',
        );
    }

    #[Test]
    public function validateCsrfRejectsEmptyPostedToken(): void
    {
        Auth::csrfToken(); // ensure a token is present in session
        $this->assertFalse(Auth::validateCsrf(''));
    }

    // ---------------------------------------------------------------
    //  Role
    // ---------------------------------------------------------------

    #[Test]
    public function getUserRoleReturnsUserAsDefault(): void
    {
        $this->assertSame('user', Auth::getUserRole());
    }

    // ---------------------------------------------------------------
    //  Login state (defaults when not logged in)
    // ---------------------------------------------------------------

    #[Test]
    public function isLoggedInReturnsFalseWhenNotLoggedIn(): void
    {
        $this->assertFalse(Auth::isLoggedIn());
    }

    #[Test]
    public function getUserIdReturnsZeroWhenNotLoggedIn(): void
    {
        $this->assertSame(0, Auth::getUserId());
    }

    #[Test]
    public function getUsernameReturnsEmptyStringWhenNotLoggedIn(): void
    {
        $this->assertSame('', Auth::getUsername());
    }

    // ---------------------------------------------------------------
    //  Login / logout (requires database)
    // ---------------------------------------------------------------

    #[Test]
    public function attemptLoginRequiresDatabase(): void
    {
        $this->markTestSkipped('TODO: needs database setup and test user');
    }

    #[Test]
    public function logoutClearsSession(): void
    {
        $this->markTestSkipped('TODO: needs pre-configured session state');
    }
}
