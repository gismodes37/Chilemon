<?php
declare(strict_types=1);

namespace Tests\Companion;

use App\Companion\InstallerPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstallerPayload::class)]
final class InstallerPayloadTest extends TestCase
{
    private function builder(): InstallerPayload
    {
        return new InstallerPayload(
            dirname(__DIR__, 2) . '/companion/installer-template.bat.tpl'
        );
    }

    #[Test]
    public function builds_a_crlf_batch_with_no_placeholders(): void
    {
        $config = "[peer]\npassword = \"abc\"\n";
        $bat = $this->builder()->build($config, 'main');

        self::assertStringStartsWith('@echo off', $bat);
        self::assertStringNotContainsString('__CHILEMON_CONFIG_B64__', $bat);
        self::assertStringNotContainsString('__CHILEMON_REPO_REF__', $bat);
        self::assertStringContainsString(base64_encode($config), $bat);
        self::assertStringContainsString('refs/heads/main.zip', $bat);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $bat));
    }

    #[Test]
    public function rejects_unsafe_repo_refs(): void
    {
        foreach ([
            'main.bat & calc', '../evil', 'a b', '', "main\r\ncalc",
            'main//x', '/main', 'main/', 'x..y', "main`calc", 'main|calc',
        ] as $ref) {
            $caught = null;
            try {
                $this->builder()->build('config', $ref);
            } catch (\InvalidArgumentException $exception) {
                $caught = $exception;
            }
            self::assertInstanceOf(
                \InvalidArgumentException::class,
                $caught,
                'Unsafe repo ref was accepted: ' . $ref
            );
        }
    }

    #[Test]
    public function fails_loudly_when_the_template_is_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        (new InstallerPayload(
            dirname(__DIR__, 2) . '/companion/no-such-template.tpl'
        ))->build('config', 'main');
    }
}
