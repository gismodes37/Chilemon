<?php
declare(strict_types=1);

echo "\n👤 ChileMon - Crear Usuario\n";
echo "----------------------------------\n";

function isWindows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
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

    // Windows: no se puede ocultar fácil sin dependencias extra.
    if (isWindows()) {
        echo "(Windows: entrada visible) ";
        return trim((string) fgets(STDIN));
    }

    // Linux/macOS: ocultar con stty
    $disabled = false;
    try {
        @system('stty -echo 2>/dev/null', $code);
        if ($code === 0) {
            $disabled = true;
        }
        $value = trim((string) fgets(STDIN));
        echo "\n";
        return $value;
    } finally {
        if ($disabled) {
            @system('stty echo 2>/dev/null');
        }
    }
}

function env(string $key): ?string
{
    $v = getenv($key);
    if ($v === false) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}

/**
 * BasePath:
 * - Dev/local: CHILEMON_BASE_PATH
 * - Producción: /opt/chilemon
 */
$basePath = env('CHILEMON_BASE_PATH') ?? '/opt/chilemon';
$basePath = isWindows()
    ? rtrim(str_replace('/', '\\', $basePath), "\\")
    : rtrim($basePath, "/");

$dbPath = $basePath . (isWindows() ? '\\data\\chilemon.sqlite' : '/data/chilemon.sqlite');

// Extensiones requeridas
if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3')) {
    if (isWindows()) {
        fail(
            "Faltan extensiones SQLite (pdo_sqlite/sqlite3).\n" .
            "En XAMPP habilita en C:\\xampp\\php\\php.ini:\n" .
            "  extension=pdo_sqlite\n" .
            "  extension=sqlite3\n" .
            "Luego reinicia Apache/terminal y reintenta."
        );
    }
    fail("Faltan extensiones SQLite. Instala: sudo apt install php-sqlite3");
}

echo "📌 DB Path: {$dbPath}\n\n";

if (!is_file($dbPath)) {
    fail("Base de datos no encontrada. Ejecuta bin/install.php primero (o revisa CHILEMON_BASE_PATH).");
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    $username = trim(prompt("Usuario (min 3 chars, sin espacios): "));

    if (strlen($username) < 3) {
        fail("Usuario inválido (mínimo 3 caracteres).");
    }

    // Permitir solo caracteres seguros (3-32)
    if (!preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $username)) {
        fail("Usuario inválido. Usa solo letras, números, punto, guion y guion bajo (3-32 chars).");
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        fail("El usuario ya existe.");
    }

    $password = promptHidden("Contraseña (min 8 chars): ");
    if (strlen($password) < 8) {
        fail("Contraseña muy corta (mínimo 8 caracteres).");
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

    echo "✅ Usuario creado correctamente: {$username}\n";

} catch (Throwable $e) {
    fail("Error: " . $e->getMessage());
}