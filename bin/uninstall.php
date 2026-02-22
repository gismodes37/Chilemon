<?php
declare(strict_types=1);

echo "\n🗑 ChileMon - Uninstall\n";
echo "----------------------------------\n";

$dbPath = '/opt/chilemon/data/chilemon.sqlite';

if (!file_exists($dbPath)) {
    echo "Nada que desinstalar.\n";
    exit(0);
}

echo "Esto eliminará la base de datos.\n";
echo "¿Está seguro? (s/N): ";

$confirm = strtolower(trim(fgets(STDIN)));

if ($confirm !== 's') {
    echo "Abortado.\n";
    exit(0);
}

unlink($dbPath);

echo "✅ Base de datos eliminada.\n";