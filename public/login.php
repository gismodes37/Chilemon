<?php
declare(strict_types=1);

/**
 * public/login.php
 * ------------------------------------------------
 * Controlador de autenticación.
 * - GET  → muestra el formulario de login
 * - POST → valida credenciales y redirige al dashboard
 *
 * Seguridad:
 *   - CSRF token en sesión, validado en cada POST
 *   - Rate limiting delegado a Auth::attemptLogin()
 *   - Redirect post-login usa BASE_URL (absoluto) para
 *     evitar problemas con BASE_PATH en entornos donde
 *     Apache resuelve SCRIPT_NAME como "/" (Alias directo)
 */

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

// Iniciar sesión con flags de seguridad (httponly, samesite, etc.)
Auth::startSession();

// Generar token CSRF si no existe en la sesión actual
if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = null;
$oldUser = '';

// -------------------------------------------------------
// Construir URL base para redirects post-login/logout.
// Se usa BASE_URL (absoluto) en lugar de BASE_PATH (relativo)
// porque cuando Apache apunta el Alias directamente a public/,
// dirname(SCRIPT_NAME) puede devolver "/" en lugar de "/chilemon",
// dejando $bp vacío y redirigiendo a /index.php (raíz del servidor).
//
// BASE_URL ya está calculado correctamente en config/app.php:
//   https://node61916.local/chilemon
// -------------------------------------------------------
$baseUrl = defined('BASE_URL') ? rtrim((string) BASE_URL, '/') : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $oldUser = (string) ($_POST['username'] ?? '');

    // Validar token CSRF antes de procesar cualquier dato
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        $error = 'Solicitud inválida. Intenta nuevamente.';
    } else {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');

        if ($u === '' || $p === '') {
            $error = 'Completa usuario y contraseña.';
        } else {
            if (Auth::attemptLogin($u, $p)) {
                // Rotar token CSRF después de login exitoso (buena práctica)
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                // Redirigir al dashboard usando URL absoluta
                // Resultado: https://nodeYOUR_NODE.local/chilemon/index.php
                header('Location: ' . $baseUrl . '/index.php');
                exit;
            }

            // Login fallido: mostrar mensaje genérico o rate-limit
            $last  = Auth::getLastError();
            $error = ($last && stripos($last, 'Demasiados intentos') !== false)
                ? $last
                : 'Usuario o contraseña incorrectos.';
        }
    }
}

// Cargar vista del formulario de login
$view = ROOT_PATH . '/app/Views/auth/login.view.php';
if (!is_file($view)) {
    http_response_code(500);
    echo 'Vista no encontrada: ' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
    exit;
}

require $view;