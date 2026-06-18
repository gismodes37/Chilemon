<?php
declare(strict_types=1);

namespace Tests\Asterisk;

use App\Services\AslRptService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AslRptService::class)]
final class AslRptServiceTest extends TestCase
{
    #[Test]
    public function stats(): void
    {
        $this->markTestIncomplete('TODO: requires AslRptService mocking strategy');
    }

    #[Test]
    public function nodes(): void
    {
        $this->markTestIncomplete('TODO: requires AslRptService mocking strategy');
    }

    #[Test]
    public function connect(): void
    {
        $this->markTestIncomplete('TODO: requires AslRptService mocking strategy');
    }

    #[Test]
    public function disconnect(): void
    {
        $this->markTestIncomplete('TODO: requires AslRptService mocking strategy');
    }

    #[Test]
    public function constructorThrowsWithoutNodeId(): void
    {
        $this->markTestIncomplete('TODO: requires AslRptService mocking strategy');
    }
}
