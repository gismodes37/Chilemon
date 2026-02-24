<?php
declare(strict_types=1);

echo "\n👤 ChileMon - Crear Usuario\n";
echo "----------------------------------\n";

$dbPath = '/opt/chilemon/data/chilemon.sqlite';

function fail(string $msg, int $code = 1): void {
    echo "❌ {$msg}\n";
    exit($code);
}

function prompt(string $label): string {
    echo $label;
    return trim((string) fgets(STDIN));
}

function promptHidden(string $label): string {
    echo $label;
    @system('stty -echo');
    $value = trim((string) fgets(STDIN));
    @system('stty echo');
    echo "\n";
    return $value;
}

if (!file_exists($dbPath)) {
    fail("Base de datos no encontrada. Ejecute bin/install.php primero.");
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $username = prompt("Usuario (min 3 chars): ");
    if (strlen($username) < 3) fail("Usuario inválido (mínimo 3 caracteres).");

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) fail("El usuario ya existe.");

    $password = promptHidden("Contraseña (min 8 chars): ");
    if (strlen($password) < 8) fail("Contraseña muy corta (mínimo 8 caracteres).");

    $confirm = promptHidden("Confirmar contraseña: ");
    if ($password !== $confirm) fail("Las contraseñas no coinciden.");

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) fail("No se pudo generar hash de contraseña.");

    $insert = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $insert->execute([$username, $hash]);

    echo "✅ Usuario creado correctamente: {$username}\n";

} catch (Throwable $e) {
    fail("Error: " . $e->getMessage());
}