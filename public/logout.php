<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

Auth::startSession();

// Solo permitir POST para logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

// Validar CSRF
$csrf = (string)($_POST['csrf_token'] ?? '');
if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals((string)$_SESSION['csrf_token'], $csrf)
) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

// Ejecutar logout
Auth::logout();

// Invalidar token
$_SESSION = [];
session_destroy();

// Redirección consistente
$bp = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : ''), '/');
header('Location: ' . ($bp === '' ? '' : $bp) . '/login.php');
exit;