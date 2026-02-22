<?php
declare(strict_types=1);

echo "\n👤 ChileMon - Crear Usuario\n";
echo "----------------------------------\n";

$dbPath = '/opt/chilemon/data/chilemon.sqlite';

if (!file_exists($dbPath)) {
    echo "❌ Base de datos no encontrada. Ejecute install.php primero.\n";
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Usuario: ";
    $username = trim(fgets(STDIN));

    if (strlen($username) < 3) {
        echo "❌ Usuario inválido\n";
        exit(1);
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);

    if ($stmt->fetch()) {
        echo "❌ El usuario ya existe\n";
        exit(1);
    }

    echo "Contraseña (mínimo 8 caracteres): ";
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";

    if (strlen($password) < 8) {
        echo "❌ Contraseña muy corta\n";
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $insert->execute([$username, $hash]);

    echo "✅ Usuario creado correctamente\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}