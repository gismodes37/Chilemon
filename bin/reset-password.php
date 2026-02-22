<?php
declare(strict_types=1);

echo "\n🔐 ChileMon - Reset Password\n";
echo "----------------------------------\n";

$dbPath = '/opt/chilemon/data/chilemon.sqlite';

if (!file_exists($dbPath)) {
    echo "❌ Base de datos no encontrada.\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Usuario: ";
    $username = trim(fgets(STDIN));

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);

    if (!$stmt->fetch()) {
        echo "❌ Usuario no encontrado\n";
        exit(1);
    }

    echo "Nueva contraseña: ";
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    if (strlen($password) < 8) {
        echo "❌ Contraseña muy corta\n";
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $update = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
    $update->execute([$hash, $username]);

    echo "✅ Contraseña actualizada\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}