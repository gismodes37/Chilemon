<?php
require __DIR__ . '/../app/Core/Database.php';

$db = \App\Core\Database::getConnection();
$result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
$tables = $result->fetchAll(PDO::FETCH_COLUMN);

echo '<pre>';
print_r($tables);