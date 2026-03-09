<?php
declare(strict_types=1);

namespace App\Asterisk;

/**
 * AmiClient
 * Cliente AMI de bajo nivel:
 * - Abre socket
 * - Login
 * - Envía acciones
 * - Lee respuestas
 * - Logoff
 */
final class AmiClient
{
    /** @var resource|null */
    private $fp = null;

    /**
     * ---------------------------------------
     * Conectar al socket AMI
     * ---------------------------------------
     */
    public function connect(string $host, int $port, int $timeout = 3): void
    {
        $errno = 0;
        $errstr = '';

        $this->fp = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if (!$this->fp) {
            throw new \RuntimeException("AMI socket error: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->fp, $timeout);

        // Leer banner de Asterisk (1 línea suele bastar)
        @fgets($this->fp, 2048);
    }

    /**
     * ---------------------------------------
     * Login AMI
     * ---------------------------------------
     */
    public function login(string $user, string $pass): void
    {
        $payload =
            "Action: Login\r\n" .
            "Username: {$user}\r\n" .
            "Secret: {$pass}\r\n" .
            "Events: off\r\n\r\n";

        $this->write($payload);

        $resp = $this->readMessageBlock();

        // Validación mínima de éxito
        if (stripos($resp, "Response: Success") === false) {
            throw new \RuntimeException("AMI login failed. Raw: " . trim($resp));
        }
    }

    /**
     * ---------------------------------------
     * Ejecutar "Action: Command"
     * Lee la salida completa hasta CommandComplete.
     * ---------------------------------------
     */
    public function command(string $command): string
    {
        $payload =
            "Action: Command\r\n" .
            "Command: {$command}\r\n\r\n";

        $this->write($payload);

        return $this->readCommandResponse();
    }

    /**
     * ---------------------------------------
     * Logoff AMI (importante para no “ensuciar” logs/CLI)
     * ---------------------------------------
     */
    public function logoff(): void
    {
        if (!$this->fp) return;

        $payload = "Action: Logoff\r\n\r\n";
        $this->write($payload);

        // Consumir bloque final (best-effort)
        $this->readMessageBlock();
    }

    /**
     * ---------------------------------------
     * Cerrar socket
     * ---------------------------------------
     */
    public function close(): void
    {
        if ($this->fp) {
            @fclose($this->fp);
            $this->fp = null;
        }
    }

    /**
     * ---------------------------------------
     * Helpers internos: escribir al socket
     * ---------------------------------------
     */
    private function write(string $data): void
    {
        if (!$this->fp) {
            throw new \RuntimeException("AMI socket not connected");
        }

        $ok = @fwrite($this->fp, $data);

        if ($ok === false) {
            throw new \RuntimeException("AMI write failed");
        }
    }

    /**
     * ---------------------------------------
     * Leer “bloque” AMI estándar (hasta línea en blanco)
     * Útil para Login/Logoff.
     * ---------------------------------------
     */
    private function readMessageBlock(): string
    {
        if (!$this->fp) return '';

        $resp = '';

        while (!feof($this->fp)) {
            $line = fgets($this->fp, 2048);
            if ($line === false) break;

            $resp .= $line;

            // Fin de bloque AMI
            if ($line === "\r\n") break;
        }

        return $resp;
    }

    /**
     * ---------------------------------------
     * Leer respuesta de "Action: Command"
     * AMI devuelve varios bloques:
     * - Response: Success
     * - Output: ...
     * - Event: CommandComplete
     * Leemos hasta encontrar "Event: CommandComplete"
     * ---------------------------------------
     */
    private function readCommandResponse(): string
    {
        if (!$this->fp) return '';

        $resp = '';
        $sawComplete = false;

        while (!feof($this->fp)) {
            $line = fgets($this->fp, 2048);
            if ($line === false) break;

            $resp .= $line;

            if (stripos($resp, "Event: CommandComplete") !== false) {
                $sawComplete = true;
            }

            // cuando ya vimos CommandComplete y llegó cierre de bloque
            if ($sawComplete && $line === "\r\n") {
                break;
            }
        }

        return $resp;
    }
}