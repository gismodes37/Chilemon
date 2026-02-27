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

        $config = require __DIR__ . '/../../config/database.php';

        if (($config['driver'] ?? '') !== 'sqlite') {
            throw new \RuntimeException('Solo SQLite está soportado.');
        }

        $dbPath = $config['sqlite']['path'] ?? null;
        if (!$dbPath || !is_string($dbPath)) {
            throw new \RuntimeException('SQLite path no configurado.');
        }

        // Normaliza path (muy importante en Windows)
        $dbPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dbPath);

        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear directorio DB: ' . $dir);
        }

        try {
            self::$instance = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Recomendado: integridad referencial
            self::$instance->exec('PRAGMA foreign_keys = ON;');

        } catch (PDOException $e) {
            throw new \RuntimeException('Error SQLite: ' . $e->getMessage(), 0, $e);
        }

        return self::$instance;
    }

    public static function getConnection(): PDO
    {
        return self::getInstance();
    }
}