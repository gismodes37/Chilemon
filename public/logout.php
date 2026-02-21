<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

Auth::logout();

// Redirección consistente en /chilemon
header('Location: ' . rtrim(BASE_PATH, '/') . '/login.php');
exit;
