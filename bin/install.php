<?php
declare(strict_types=1);

/**
 * ChileMon Installer v1.0.3 (portable)
 * - Producción (Raspberry): default /opt/chilemon
 * - Dev (Windows/XAMPP): soporta CHILEMON_BASE_PATH
 * - Crea DB SQLite si no existe
 * - Aplica schema.sql SIEMPRE (IF NOT EXISTS) para soportar upgrades
 * - Crea carpetas data/logs/backups
 * - Crea usuario admin interactivo solo si hay terminal disponible
 */

echo "\n🇨🇱 ChileMon Installer v1.0.3\n";
echo "----------------------------------\n";

function isWindows(): bool
{
    return strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN';
}

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

    // En Windows, stty no existe: degradamos a visible.
    if (isWindows()) {
        echo "(Windows: entrada visible) ";
        return trim((string) fgets(STDIN));
    }

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
        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }
    return get_current_user() ?: 'unknown';
}

function env(string $key): ?string
{
    $v = getenv($key);
    if ($v === false) {
        return null;
    }
    $v = trim($v);
    return $v === '' ? null : $v;
}

/**
 * Detecta si el script puede interactuar con una terminal real.
 */
function isInteractiveCli(): bool
{
    if (PHP_SAPI !== 'cli') {
        return false;
    }

    if (!defined('STDIN')) {
        return false;
    }

    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }

    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }

    return false;
}

/**
 * BasePath:
 * - Si CHILEMON_BASE_PATH está definido, úsalo (dev/local).
 * - Si no, default /opt/chilemon (producción).
 */
$basePath = env('CHILEMON_BASE_PATH') ?? '/opt/chilemon';

// Normaliza separadores en Windows
if (isWindows()) {
    $basePath = rtrim(str_replace('/', '\\', $basePath), "\\");
} else {
    $basePath = rtrim($basePath, "/");
}

$dbPath = $basePath . (isWindows() ? '\\data\\chilemon.sqlite' : '/data/chilemon.sqlite');
$schema = $basePath . (isWindows() ? '\\install\\sql\\schema.sql' : '/install/sql/schema.sql');
$interactive = isInteractiveCli();

echo "📌 BasePath : {$basePath}\n";
echo "📌 DB Path  : {$dbPath}\n";
echo "📌 Schema   : {$schema}\n";
echo "📌 Usuario actual (CLI): " . currentUser() . "\n";
echo "📌 Modo interactivo: " . ($interactive ? 'sí' : 'no') . "\n\n";

// Extensiones requeridas
if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3')) {
    if (isWindows()) {
        fail(
            "Faltan extensiones SQLite (pdo_sqlite/sqlite3).\n" .
            "En XAMPP habilita en C:\\xampp\\php\\php.ini:\n" .
            "  extension=pdo_sqlite\n" .
            "  extension=sqlite3\n" .
            "Luego reinicia Apache y reintenta."
        );
    }
    fail("Faltan extensiones SQLite. Instala: sudo apt install php-sqlite3");
}

if (!is_dir($basePath)) {
    if (isWindows()) {
        fail(
            "No existe {$basePath}.\n" .
            "En Windows define CHILEMON_BASE_PATH, por ejemplo:\n" .
            "  setx CHILEMON_BASE_PATH \"C:\\xampp\\htdocs\\chilemon\"\n" .
            "Cierra y abre terminal, o usa en la sesión actual:\n" .
            "  \$env:CHILEMON_BASE_PATH=\"C:\\xampp\\htdocs\\chilemon\""
        );
    }
    fail("No existe {$basePath}. Instala ChileMon en /opt/chilemon o ajusta el instalador.");
}

if (!is_file($schema) || !is_readable($schema)) {
    fail("No se encontró o no se puede leer el schema: {$schema}");
}

// Carpetas necesarias
$dirs = [
    $basePath . (isWindows() ? '\\data' : '/data'),
    $basePath . (isWindows() ? '\\logs' : '/logs'),
    $basePath . (isWindows() ? '\\backups' : '/backups'),
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "📁 Creando carpeta: {$dir}\n";
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            fail("No se pudo crear {$dir}");
        }
    }
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Recomendado en SQLite
    $pdo->exec("PRAGMA foreign_keys = ON;");

    // Aplicar schema SIEMPRE (soporta upgrades sin romper)
    echo "📦 Aplicando schema.sql (upgrade-safe)...\n";
    $sql = file_get_contents($schema);
    if ($sql === false || trim($sql) === '') {
        fail("schema.sql está vacío o no se pudo leer.");
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    echo "✅ Schema aplicado.\n";

    /**
     * Si no hay terminal interactiva, no intentamos crear usuario.
     * Esto permite que install/install_chilemon.sh ejecute este script
     * como www-data sin quedarse bloqueado esperando input.
     */
    if (!$interactive) {
        echo "ℹ️  No hay terminal interactiva disponible.\n";
        echo "ℹ️  Se omite la creación de usuario en este paso.\n";
        echo "ℹ️  Luego puede crear el usuario admin ejecutando este script manualmente en terminal.\n";
        echo "🚀 Instalación/upgrade finalizado\n\n";
        exit(0);
    }

    // ¿Ya existe algún usuario?
    $hasAnyUser = (int)($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);

    if ($hasAnyUser > 0) {
        echo "⚠️  Ya existen usuarios en la DB.\n";
        $ans = strtolower(prompt("¿Deseas crear un usuario adicional? (s/N): "));
        if ($ans !== 's') {
            echo "OK. Instalación/upgrade finalizado sin crear usuario.\n";
            exit(0);
        }
    } else {
        echo "👤 No hay usuarios. Se creará el primer usuario (admin).\n";
    }

    echo "\n👤 Crear usuario\n";

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

    $insert = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $insert->execute([$username, $hash]);

    echo "\n✅ Usuario creado correctamente: {$username}\n";
    echo "🚀 Instalación finalizada\n\n";

    if (isWindows()) {
        echo "Accede en: http://localhost/chilemon/\n\n";
    } else {
        echo "Accede en: https://<tu-nodo>/chilemon/\n\n";
        echo "Si su servidor ya tiene HTTPS activo, pruebe también: https://<tu-nodo>/chilemon/\n\n";

    }

} catch (Throwable $e) {
    fail("Error: " . $e->getMessage());
}