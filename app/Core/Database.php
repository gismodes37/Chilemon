<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        // Carga config (DEBE retornar array)
        $config = require __DIR__ . '/../../config/database.php';

        if (($config['driver'] ?? '') !== 'sqlite') {
            throw new \RuntimeException('Solo SQLite está soportado.');
        }

        $dbPath = $config['sqlite']['path'] ?? null;
        if (!$dbPath) {
            throw new \RuntimeException('SQLite path no configurado.');
        }

        // Crear carpeta si no existe
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear directorio DB: ' . $dir);
        }

        try {
            self::$instance = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Error SQLite: ' . $e->getMessage(), 0, $e);
        }

        return self::$instance;
    }

    /**
     * Compatibilidad con código legacy que llama getConnection()
     */
    public static function getConnection(): PDO
    {
        return self::getInstance();
    }
}
