<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

Auth::startSession();

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = null;
$oldUser = '';

$bp = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
if ($bp === '/') $bp = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $oldUser = (string)($_POST['username'] ?? '');

    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
        $error = 'Solicitud inválida. Intenta nuevamente.';
    } else {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');

        if ($u === '' || $p === '') {
            $error = 'Completa usuario y contraseña.';
        } else {
            if (Auth::attemptLogin($u, $p)) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $bp . '/index.php');
                exit;
            }

            $last = Auth::getLastError();
            $error = ($last && stripos($last, 'Demasiados intentos') !== false)
                ? $last
                : 'Usuario o contraseña incorrectos.';
        }
    }
}

$view = ROOT_PATH . '/app/Views/auth/login.view.php';
if (!is_file($view)) {
    http_response_code(500);
    echo 'Vista no encontrada: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
    exit;
}

require $view;