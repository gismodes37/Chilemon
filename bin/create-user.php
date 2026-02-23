<?php
declare(strict_types=1);

/**
 * ChileMon Create User v1.0.1
 * - Crea usuarios en SQLite (sin romper NOT NULL)
 * - Valida duplicados
 * - Password oculto en terminal
 *
 * Recomendación: ejecutar como www-data:
 *   sudo -u www-data php bin/create-user.php
 */

echo "\n👤 ChileMon - Create User v1.0.1\n";
echo "----------------------------------\n";

function fail(string $msg, int $code = 1): void
{
    fwrite(STDERR, "❌ {$msg}\n");
    exit($code);
}

function ok(string $msg): void
{
    echo "✅ {$msg}\n";
}

function info(string $msg): void
{
    echo "ℹ️  {$msg}\n";
}

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function promptHidden(string $label): string
{
    echo $label;
    @system('stty -echo');
    $value = trim((string) fgets(STDIN));
    @system('stty echo');
    echo "\n";
    return $value;
}

$basePath = realpath(dirname(__DIR__));
if ($basePath === false) {
    fail("No se pudo resolver el directorio base del proyecto (dirname(__DIR__)).");
}

$dataDir = $basePath . '/data';
$dbPath  = $dataDir . '/chilemon.sqlite';

info("BasePath: {$basePath}");
info("DB Path : {$dbPath}");

if (!is_dir($dataDir)) {
    fail("No existe el directorio {$dataDir}. Ejecuta primero: sudo -u www-data php bin/install.php");
}

if (!file_exists($dbPath)) {
    fail("No existe la base de datos. Ejecuta primero: sudo -u www-data php bin/install.php");
}

if (!is_writable($dataDir)) {
    fail(
        "No hay permisos de escritura en {$dataDir}.\n" .
        "Ejecuta este script como www-data:\n" .
        "  sudo -u www-data php bin/create-user.php"
    );
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Confirmar que tabla users existe
    $hasUsersTable = (bool) $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")
        ->fetchColumn();

    if (!$hasUsersTable) {
        fail("La tabla users no existe. Ejecuta primero el instalador: sudo -u www-data php bin/install.php");
    }

    // Datos
    $username = prompt("Usuario (min 3 chars): ");
    if (strlen($username) < 3) {
        fail("El usuario debe tener al menos 3 caracteres.");
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        fail("El usuario '{$username}' ya existe.");
    }

    $password = promptHidden("Contraseña (min 8 chars): ");
    if (strlen($password) < 8) {
        fail("La contraseña debe tener mínimo 8 caracteres.");
    }

    $confirm = promptHidden("Confirmar contraseña: ");
    if ($password !== $confirm) {
        fail("Las contraseñas no coinciden.");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
        fail("No se pudo generar hash de contraseña.");
    }

    // Insert: el created_at lo resuelve el DEFAULT del schema (robusto)
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $ins->execute([$username, $hash]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ok("Usuario creado correctamente: {$username}");
    echo "\n";

} catch (Throwable $e) {
    fail("Error: " . $e->getMessage());
}