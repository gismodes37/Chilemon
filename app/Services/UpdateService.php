<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * UpdateService — Wrapper PHP para actualizar ChileMon desde GitHub.
 *
 * Delega a bin/chilemon-rpt (via sudo) para todas las operaciones Git
 * y de servicios del sistema.
 *
 * Sigue exactamente el mismo patrón que AslRptService:
 * - ALLOWED constant con lista blanca de comandos
 * - run() privado con escapeshellarg(), exec(), y chequeo de exit code
 * - Windows mock automático para desarrollo local
 */
final class UpdateService
{
    private const ALLOWED = [
        'git-fetch',
        'git-compare',
        'git-pull',
        'sys-restart-webrtc',
        'sys-reload-apache',
    ];

    private string $wrapper;

    public function __construct(
        string $wrapperPath = '/usr/local/bin/chilemon-rpt'
    ) {
        $this->wrapper = $wrapperPath;
    }

    /**
     * Verifica si hay actualizaciones disponibles.
     *
     * Ejecuta git-fetch para obtener refs remotas, luego git-compare
     * para comparar HEAD local contra origin/main.
     *
     * @return array{ok: bool, update_available: bool, local_commit: string, remote_commit: string, summary: string}
     */
    public function check(): array
    {
        if ($this->isWindows()) {
            return $this->windowsMockCheck();
        }

        // Step 1: actualizar refs remotas
        $this->run('git-fetch');

        // Step 2: comparar HEAD vs origin/main
        $output = $this->run('git-compare');

        return self::parseCheckOutput($output);
    }

    /**
     * Aplica una actualización: git pull + reinicio de servicios.
     *
     * Ejecuta git-pull (stash automático + pull), luego reinicia
     * chilemon-webrtc y recarga Apache.
     *
     * @return array{success: bool, action: string, message: string, stashed: bool, commit: string}
     */
    public function apply(): array
    {
        if ($this->isWindows()) {
            return $this->windowsMockApply();
        }

        // Step 1: git pull (con stash automático)
        $output = $this->run('git-pull');
        $result = self::parsePullOutput($output);

        // Step 2: reiniciar servicios solo si el pull fue exitoso
        if ($result['success']) {
            $messages = [];

            try {
                $this->run('sys-restart-webrtc');
                $messages[] = 'chilemon-webrtc restarted.';
            } catch (RuntimeException $e) {
                $messages[] = 'chilemon-webrtc restart failed: ' . $e->getMessage();
                $result['success'] = false;
            }

            try {
                $this->run('sys-reload-apache');
                $messages[] = 'Apache reloaded.';
            } catch (RuntimeException $e) {
                $messages[] = 'Apache reload failed: ' . $e->getMessage();
                $result['success'] = false;
            }

            $result['message'] .= ' ' . implode(' ', $messages);
        }

        return $result;
    }

    // ---------------------------------------------------------------
    //  Output Parsers (public static para testabilidad)
    // ---------------------------------------------------------------

    /**
     * Parsea la salida del comando git-compare.
     *
     * Formato esperado:
     *   LOCAL:<sha>
     *   REMOTE:<sha>
     *   SUMMARY:<texto>
     *
     * @param string $output Salida cruda del wrapper
     * @return array{ok: bool, update_available: bool, local_commit: string, remote_commit: string, summary: string}
     */
    public static function parseCheckOutput(string $output): array
    {
        $local  = '';
        $remote = '';
        $summary = '';

        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'LOCAL:')) {
                $local = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'REMOTE:')) {
                $remote = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'SUMMARY:')) {
                $summary = trim(substr($line, 8));
            }
        }

        $updateAvailable = $local !== '' && $remote !== '' && $local !== $remote;

        return [
            'ok'                => true,
            'update_available'  => $updateAvailable,
            'local_commit'      => $local,
            'remote_commit'     => $remote,
            'summary'           => $summary,
        ];
    }

    /**
     * Parsea la salida del comando git-pull.
     *
     * Formato esperado:
     *   [git pull output...]
     *   STASHED:<exit_code>
     *
     * Donde STASHED captura el exit code de git pull origin main.
     * 0 = pull exitoso, != 0 = pull falló.
     *
     * @param string $output Salida cruda del wrapper
     * @return array{success: bool, action: string, message: string, stashed: bool, commit: string}
     */
    public static function parsePullOutput(string $output): array
    {
        $lines          = explode("\n", trim($output));
        $stashedCode    = '';
        $commit         = '';
        $alreadyUpToDate = false;
        $pullFailed     = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, 'STASHED:')) {
                $stashedCode = trim(substr($trimmed, 8));
            } elseif (str_starts_with($trimmed, 'Updating ')) {
                // "Updating abc123..def456"
                $parts = explode('..', substr($trimmed, 9));
                if (isset($parts[1])) {
                    $commit = trim($parts[1]);
                }
            } elseif (stripos($trimmed, 'Already up to date') !== false) {
                $alreadyUpToDate = true;
            } elseif (
                stripos($trimmed, 'error:') !== false
                || stripos($trimmed, 'fatal:') !== false
                || stripos($trimmed, 'CONFLICT') !== false
            ) {
                $pullFailed = true;
            }
        }

        // STASHED:0 = pull exitoso, otro valor = falló
        $pullExitOk = ($stashedCode === '0' || $stashedCode === '');

        if ($alreadyUpToDate || trim($output) === '') {
            return [
                'success' => true,
                'action'  => 'apply-update',
                'message' => 'Already up to date. No update applied.',
                'stashed' => false,
                'commit'  => '',
            ];
        }

        if ($pullFailed || !$pullExitOk) {
            return [
                'success' => false,
                'action'  => 'apply-update',
                'message' => 'Git pull failed.',
                'stashed' => false,
                'commit'  => '',
            ];
        }

        return [
            'success' => true,
            'action'  => 'apply-update',
            'message' => 'Update applied successfully.',
            'stashed' => ($stashedCode === '0'), // true = pull succeeded OR stash was applied; best-effort
            'commit'  => $commit,
        ];
    }

    // ---------------------------------------------------------------
    //  Command Validation (pública para tests)
    // ---------------------------------------------------------------

    /**
     * Verifica si un comando está en la lista blanca ALLOWED.
     */
    public static function isAllowed(string $cmd): bool
    {
        return in_array($cmd, self::ALLOWED, true);
    }

    // ---------------------------------------------------------------
    //  Private
    // ---------------------------------------------------------------

    /**
     * Ejecuta un comando del wrapper con exec() y valida exit code.
     *
     * @throws RuntimeException si el comando no está permitido o el exit code != 0
     */
    private function run(string $cmd): string
    {
        if (!self::isAllowed($cmd)) {
            throw new RuntimeException("Comando no permitido: {$cmd}");
        }

        if ($this->isWindows()) {
            throw new RuntimeException("UpdateService no ejecutable en Windows directamente");
        }

        $parts   = ['sudo'];
        $parts[] = escapeshellarg($this->wrapper);
        $parts[] = escapeshellarg($cmd);

        $fullCmd = implode(' ', $parts) . ' 2>&1';
        $output  = [];
        $exitCode = -1;

        error_log("[UpdateService] Executing: {$fullCmd}");
        exec($fullCmd, $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            error_log("[UpdateService] Failed (exit={$exitCode}): {$outputStr}");
            throw new RuntimeException("Wrapper falló (exit={$exitCode}): {$outputStr}");
        }

        return $outputStr;
    }

    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN';
    }

    /**
     * @return array{ok: bool, update_available: bool, local_commit: string, remote_commit: string, summary: string}
     */
    private function windowsMockCheck(): array
    {
        return [
            'ok'               => true,
            'update_available' => true,
            'local_commit'     => 'abc123def456abc123def456abc123def456abc1',
            'remote_commit'    => 'def456abc123def456abc123def456abc123def4',
            'summary'          => '3 commits: Fix PTT timeout, Update README, Add CSRF tests',
        ];
    }

    /**
     * @return array{success: bool, action: string, message: string, stashed: bool, commit: string}
     */
    private function windowsMockApply(): array
    {
        return [
            'success' => true,
            'action'  => 'apply-update',
            'message' => 'Update applied. chilemon-webrtc restarted. Apache reloaded.',
            'stashed' => false,
            'commit'  => 'def456abc123def456abc123def456abc123def4',
        ];
    }
}
