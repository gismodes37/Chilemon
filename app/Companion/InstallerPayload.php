<?php
declare(strict_types=1);

namespace App\Companion;

/**
 * Builds the one-action Windows installer served by the dashboard.
 *
 * The batch source lives in a versioned template so the Windows-specific
 * commands stay reviewable as a .bat-shaped artifact. Only two tokens are
 * substituted: the generated TOML (base64, to survive batch quoting) and the
 * repository ref used to download the companion source.
 */
final class InstallerPayload
{
    public const CONFIG_TOKEN = '__CHILEMON_CONFIG_B64__';
    public const REF_TOKEN = '__CHILEMON_REPO_REF__';

    public function __construct(private readonly string $templatePath)
    {
    }

    /**
     * @throws \InvalidArgumentException when the repo ref is not safe to embed
     * @throws \RuntimeException when the versioned template cannot be read
     */
    public function build(string $configToml, string $repoRef): string
    {
        if (
            preg_match('/^[A-Za-z0-9._\/-]+$/', $repoRef) !== 1
            || str_contains($repoRef, '..')
            || str_contains($repoRef, '//')
            || str_starts_with($repoRef, '/')
            || str_ends_with($repoRef, '/')
        ) {
            throw new \InvalidArgumentException(
                'Unsafe repository ref for the companion installer.'
            );
        }

        if (!is_file($this->templatePath)) {
            throw new \RuntimeException(
                'Companion installer template not found: ' . $this->templatePath
            );
        }

        $template = file_get_contents($this->templatePath);
        if ($template === false) {
            throw new \RuntimeException(
                'Companion installer template could not be read: ' . $this->templatePath
            );
        }

        $payload = str_replace(
            [self::CONFIG_TOKEN, self::REF_TOKEN],
            [base64_encode($configToml), $repoRef],
            $template
        );

        // Windows cmd needs CRLF for labels/goto; the template is LF in git.
        return str_replace(["\r\n", "\n"], "\r\n", $payload);
    }
}
