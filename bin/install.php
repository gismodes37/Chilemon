<?php
declare(strict_types=1);

/**
 * ChileMon Installer v1.0.1
 * - Crea DB SQLite si no existe
 * - Crea tablas desde schema.sql (si faltan)
 * - Crea usuario admin interactivo
 * - Si ya está instalado, ofrece crear otro usuario (no sobreescribe)
 *
 * Recomendación: ejecutar como www-data:
 *   sudo -u www-data php bin/install.php
 */

echo "\n🇨🇱 ChileMon Installer v1.0.1\n";
echo "----------------------------------\n";

function fail(string $msg, int $code = 1): void
{
    fwrite(STDERR, "❌ {$msg}\n");
    exit($code);
}

function info(string $msg): void
{
    echo "ℹ️  {$msg}\n";
}

function ok(string $msg): void
{
    echo "✅ {$msg}\n";
}

function warn(string $msg): void
{
    echo "⚠️  {$msg}\n";
}

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function promptHidden(string $label): string
{
    echo $label;
    // Oculta input en terminal (Linux)
    @system('stty -echo');
    $value = trim((string) fgets(STDIN));
    @system('stty echo');
    echo "\n";
    return $value;
}

function currentUserHint(): string
{
    $user = get_current_user();
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($euid !== null && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid($euid);
        if (is_array($pw) && isset($pw['name'])) {
            $user = $pw['name'];
        }
    }
    return $user ?: 'unknown';
}

/**
 * Resolver basePath:
 * - Preferimos el repo root asumiendo /bin/install.php
 */
$basePath = realpath(dirname(__DIR__));
if ($basePath === false) {
    fail("No se pudo resolver el directorio base del proyecto (dirname(__DIR__)).");
}

$dataDir = $basePath . '/data';
$dbPath  = $dataDir . '/chilemon.sqlite';

/**
 * Schema: mantengo tu ubicación preferida y agrego fallback.
 */
$schemaCandidates = [
    $basePath . '/install/sql/schema.sql', // tu ruta actual
    $basePath . '/schema.sql',             // fallback común
];

$schema = null;
foreach ($schemaCandidates as $cand) {
    if (is_file($cand)) {
        $schema = $cand;
        break;
    }
}

if ($schema === null) {
    fail("No se encontró schema.sql. Busqué en:\n- " . implode("\n- ", $schemaCandidates));
}

info("BasePath: {$basePath}");
info("DB Path : {$dbPath}");
info("Schema  : {$schema}");
info("Usuario actual (CLI): " . currentUserHint());

/**
 * Asegurar carpeta data
 */
if (!is_dir($dataDir)) {
    info("Creando carpeta data...");
    if (!mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
        fail("No se pudo crear {$dataDir}");
    }
}

/**
 * Permisos: el instalador debe poder escribir DB
 */
if (!is_writable($dataDir)) {
    fail(
        "No hay permisos de escritura en {$dataDir}.\n" .
        "Ejecuta el instalador como www-data:\n" .
        "  sudo -u www-data php bin/install.php\n" .
        "O ajusta permisos/propietario del directorio."
    );
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Detectar si existe tabla users (instalación previa)
    $hasUsersTable = (bool) $pdo
        ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")
        ->fetchColumn();

    if (!$hasUsersTable) {
        info("Creando tablas desde schema.sql...");
        $sql = file_get_contents($schema);
        if ($sql === false || trim($sql) === '') {
            fail("schema.sql está vacío o no se pudo leer.");
        }
        $pdo->exec($sql);
        ok("Tablas creadas.");
    } else {
        warn("ChileMon ya parece estar instalado (tabla users existe).");
        $ans = strtolower(prompt("¿Deseas crear un usuario adicional? (s/N): "));
        if ($ans !== 's') {
            echo "Abortado. No se hicieron cambios.\n";
            exit(0);
        }
    }

    echo "\n👤 Crear usuario administrador\n";

    // Username
    $username = prompt("Usuario (min 3 chars): ");
    if (strlen($username) < 3) {
        fail("El usuario debe tener al menos 3 caracteres.");
    }

    // Verificar duplicado
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        fail("El usuario '{$username}' ya existe.");
    }

    // Password + confirmación
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

    // Insert robusto:
    // - Incluye created_at explícito (evita el NOT NULL si schema no tiene DEFAULT)
    // - Si el schema sí tiene DEFAULT, igual funciona.
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            "INSERT INTO users (username, password, created_at)
             VALUES (?, ?, datetime('now'))"
        );
        $insert->execute([$username, $hash]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ok("Usuario creado correctamente: {$username}");
    echo "🚀 Instalación finalizada\n\n";
    echo "Accede en: https://<tu-nodo>/chilemon/\n\n";

} catch (Throwable $e) {
    fail("Error: " . $e->getMessage());
}