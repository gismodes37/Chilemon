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
$darkMode = (isset($_COOKIE['chilemon_darkmode']) && $_COOKIE['chilemon_darkmode'] === 'true');

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