<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Este script se ejecuta solo por CLI.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "No se pudo determinar ROOT.\n");
    exit(1);
}

require_once $root . '/config/app.php';
require_once $root . '/app/Core/Database.php';

use App\Core\Database;

function prompt(string $label, bool $hidden = false): string {
    if (!$hidden) {
        fwrite(STDOUT, $label);
        return trim((string)fgets(STDIN));
    }

    // ocultar password
    fwrite(STDOUT, $label);
    system('stty -echo');
    $v = trim((string)fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, "\n");
    return $v;
}

$username = '';
while ($username === '') {
    $username = prompt("Usuario admin: ");
}

$password = '';
while (strlen($password) < 6) {
    $password = prompt("Contraseña (min 6): ", true);
    if (strlen($password) < 6) {
        fwrite(STDOUT, "Contraseña muy corta.\n");
    }
}

$db = Database::getConnection();

// crear carpetas data/logs si faltan (por si el deploy las omitió)
@mkdir($root . '/data', 0755, true);
@mkdir($root . '/logs', 0755, true);

// aplicar schema
$schemaFile = __DIR__ . '/sql/schema.sql';
$schema = file_get_contents($schemaFile);
if ($schema === false) {
    fwrite(STDERR, "No se pudo leer schema.sql\n");
    exit(1);
}
$db->exec($schema);

// crear usuario (upsert “seguro”)
$hash = password_hash($password, PASSWORD_BCRYPT);
$now = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

$stmt = $db->prepare("INSERT INTO users(username, password_hash, created_at)
                      VALUES(:u,:h,:c)
                      ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash");
$stmt->execute([
    ':u' => $username,
    ':h' => $hash,
    ':c' => $now,
]);

fwrite(STDOUT, "OK: Instalación completada.\n");
fwrite(STDOUT, "Usuario: {$username}\n");
fwrite(STDOUT, "Login:   /chilemon/login.php\n");
