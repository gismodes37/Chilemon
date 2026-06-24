<?php
declare(strict_types=1);

/**
 * ChileMon - Inicialización SQLite
 *
 * Ejecuta el schema SQL para crear todas las tablas necesarias.
 * Usado por install.php y como fallback si la DB no existe.
 */

require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

$db = Database::getConnection();

$schemaFile = __DIR__ . '/sql/schema.sql';
if (!file_exists($schemaFile)) {
    // Fallback: buscar create_tables_sqlite.sql
    $schemaFile = __DIR__ . '/sql/create_tables_sqlite.sql';
}

if (!file_exists($schemaFile)) {
    fwrite(STDERR, "Error: No se encontró ningún archivo schema SQL en install/sql/\n");
    exit(1);
}

$schema = file_get_contents($schemaFile);
if ($schema === false) {
    fwrite(STDERR, "Error: No se pudo leer $schemaFile\n");
    exit(1);
}

$db->exec($schema);

echo "SQLite inicializada correctamente con schema: " . basename($schemaFile) . "\n";
