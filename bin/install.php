<?php
declare(strict_types=1);

/**
 * ChileMon Installer v1.0.1
 * - Crea DB SQLite si no existe
 * - Crea tablas desde install/sql/schema.sql (si faltan)
 * - Crea usuario admin interactivo (primer usuario)
 * - Si ya está instalado, ofrece crear otro usuario (sin sobreescribir)
 */

echo "\n🇨🇱 ChileMon Installer v1.0.1\n";
echo "----------------------------------\n";

$basePath = '/opt/chilemon';
$dbPath   = $basePath . '/data/chilemon.sqlite';
$schema   = $basePath . '/install/sql/schema.sql';

function fail(string $msg, int $code = 1): void
{
    echo "❌ {$msg}\n";
    exit($code);
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

function currentUser(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) return (string) $info['name'];
    }
    return get_current_user() ?: 'unknown';
}

echo "📌 BasePath : {$basePath}\n";
echo "📌 DB Path  : {$dbPath}\n";
echo "📌 Schema   : {$schema}\n";
echo "📌 Usuario actual (CLI): " . currentUser() . "\n\n";

if (!is_dir($basePath)) {
    fail("No existe {$basePath}. Instala ChileMon en /opt/chilemon o ajusta el instalador.");
}

if (!file_exists($schema)) {
    fail("No se encontró el schema: {$schema}");
}

if (!is_dir($basePath . '/data')) {
    echo "📁 Creando carpeta data...\n";
    if (!mkdir($basePath . '/data', 0775, true) && !is_dir($basePath . '/data')) {
        fail("No se pudo crear {$basePath}/data");
    }
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hasUsersTable = (bool) $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")
        ->fetchColumn();

    if (!$hasUsersTable) {
        echo "📦 Creando tablas desde schema.sql...\n";
        $sql = file_get_contents($schema);
        if ($sql === false || trim($sql) === '') {
            fail("schema.sql está vacío o no se pudo leer.");
        }
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->commit();
        echo "✅ Tablas creadas.\n";
    } else {
        echo "⚠️  ChileMon ya parece estar instalado (tabla users existe).\n";
        $ans = strtolower(prompt("¿Deseas crear un usuario adicional? (s/N): "));
        if ($ans !== 's') {
            echo "Abortado. No se hicieron cambios.\n";
            exit(0);
        }
    }

    echo "\n👤 Crear usuario administrador\n";

    $username = prompt("Usuario (min 3 chars): ");
    if (strlen($username) < 3) fail("El usuario debe tener al menos 3 caracteres.");

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) fail("El usuario '{$username}' ya existe.");

    $password = promptHidden("Contraseña (min 8 chars): ");
    if (strlen($password) < 8) fail("La contraseña debe tener mínimo 8 caracteres.");

    $confirm = promptHidden("Confirmar contraseña: ");
    if ($password !== $confirm) fail("Las contraseñas no coinciden.");

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) fail("No se pudo generar hash de contraseña.");

    $insert = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $insert->execute([$username, $hash]);

    echo "\n✅ Usuario creado correctamente: {$username}\n";
    echo "🚀 Instalación finalizada\n\n";
    echo "Accede en: https://<tu-nodo>/chilemon/\n\n";

} catch (Throwable $e) {
    fail("Error: " . $e->getMessage());
}