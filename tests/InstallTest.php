<?php
declare(strict_types=1);

namespace Tests;

use App\Auth\Auth;
use App\Core\Database;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InstallTest extends TestCase
{
    #[Test]
    public function authClassIsLoadable(): void
    {
        $this->assertTrue(class_exists(Auth::class));
    }

    #[Test]
    public function databaseClassIsLoadable(): void
    {
        $this->assertTrue(class_exists(Database::class));
    }
}
