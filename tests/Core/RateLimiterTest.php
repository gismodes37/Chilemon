<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    #[Test]
    public function checkSilentReturnsTrueOnFirstCall(): void
    {
        $this->markTestSkipped('TODO: needs database connection');
    }

    #[Test]
    public function checkSilentReturnsFalseAfterExceedingLimit(): void
    {
        $this->markTestSkipped('TODO: needs database connection');
    }
}
