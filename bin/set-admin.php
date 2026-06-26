<?php
declare(strict_types=1);

/**
 * bin/set-admin.php
 * -----------------------------------------------
 * Asigna rol admin a un usuario existente.
 * Crea la columna role si no existe (migración v0.4.0).
 *
 * Uso:
 *   php bin/set-admin.php <username>
 *
 * Ejemplo:
 *   php bin/set-admin.php admin
 * -----------------------------------------------
 */

$dbPath = dirname(__DIR__) . '/data/chilemon.sqlite';

if (!file_exists($dbPath)) {
    echo "❌ Base de datos no encontrada: $dbPath\n";
    exit(1);
}

$username = $argv[1] ?? '';
if ($username === '') {
    echo "Uso: php bin/set-admin.php <username>\n";
    echo "Ej:  php bin/set-admin.php admin\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Migración v0.4.0: agregar columna role si no existe
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
        echo "✅ Migración: columna 'role' agregada a users\n";
    } catch (PDOException $e) {
        // Ya existe — ignorar
        echo "ℹ️  Columna 'role' ya existe\n";
    }

    // Verificar que el usuario existe
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo "❌ Usuario '$username' no encontrado\n";
        exit(1);
    }

    // Asignar rol admin
    $update = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $update->execute([$user['id']]);

    echo "✅ Usuario '$username' ahora es admin (rol anterior: {$user['role']})\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
