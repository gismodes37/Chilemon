<?php
declare(strict_types=1);

/**
 * public/index.php — Front Controller principal del dashboard ChileMon.
 */

// 1. Cargar configuración base
require_once __DIR__ . '/../config/app.php';

// 2. Cargar Autocargador PSR-4 Nativo (reemplaza require_once manuales)
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Controllers\DashboardController;

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '0.4.0');
}

// 3. Verificación de Seguridad y Sesión
Auth::startSession();
Auth::requireLogin();

// 4. Parámetros globales de estado de interfaz
$username = Auth::getUsername() ?: 'Usuario';

// Apariencia: una sola cookie con allowlist. El flag viejo
// chilemon_darkmode se respeta como fallback y se migra en el cliente.
$validSkins = ['clasico', 'obsidian'];
$validModes = ['light', 'dark'];
$themeCookie = isset($_COOKIE['chilemon_theme']) ? (string)$_COOKIE['chilemon_theme'] : '';
$themeParts = explode(':', $themeCookie, 2);

if (count($themeParts) === 2
    && in_array($themeParts[0], $validSkins, true)
    && in_array($themeParts[1], $validModes, true)) {
    $skin = $themeParts[0];
    $mode = $themeParts[1];
} else {
    $skin = 'clasico';
    $mode = (isset($_COOKIE['chilemon_darkmode']) && $_COOKIE['chilemon_darkmode'] === 'true')
        ? 'dark'
        : 'light';
}

// Compatibilidad con las vistas que todavía consultan $darkMode.
$darkMode = ($mode === 'dark');

// 5. Invocación del Controlador
$controller = new DashboardController();
$data = $controller->index();

// 6. Extracción de variables para la vista
$nodos        = $data['nodos'];
$estadisticas = $data['estadisticas'];
$dbError      = $data['dbError'];
$systemInfo   = $data['systemInfo'];
$ipv4_list    = $data['ipv4_list'];
$ipv6_list    = $data['ipv6_list'];

// 7. Renderizar Vista
require ROOT_PATH . '/public/views/dashboard.php';