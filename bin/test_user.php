<?php
$dbPath = 'c:\xampp\htdocs\chilemon\data\chilemon.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$hash = password_hash('admin', PASSWORD_DEFAULT);

// Delete if exists then insert
$pdo->exec("DELETE FROM users WHERE username = 'admin'");
$pdo->exec("INSERT INTO users (username, password_hash) VALUES ('admin', '$hash')");

echo "Admin user created successfully.\n";
