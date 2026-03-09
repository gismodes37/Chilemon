<?php
declare(strict_types=1);

/**
 * public/index.php — Controlador principal del dashboard ChileMon.
 *
 * Fuente de nodos: AslRptService (Asterisk real) — NO SQLite.
 * SQLite se sigue usando para: favoritos, eventos, usuarios.
 *
 * Orden de carga:
 *   1. config/app.php   → ROOT_PATH, BASE_URL, ASL_NODE, APP_ENV
 *   2. APP_VERSION      → antes de Auth y partials
 *   3. Auth::startSession() + requireLogin()
 *   4. AslRptService    → nodos reales desde Asterisk
 *   5. System info      → CPU, IP, temperatura
 *   6. Render vista     → views/dashboard.php
 */

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Helpers/System.php';
require_once ROOT_PATH . '/app/Services/AslRptService.php';

use App\Auth\Auth;
use App\Helpers\System;
use App\Services\AslRptService;

// APP_VERSION debe definirse ANTES de Auth y de los partials (head.php lo usa)
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '0.4.0');
}

// Inicia sesión con parámetros correctos (cookie_path, secure, samesite)
// y redirige a login.php si no hay sesión válida.
Auth::startSession();
Auth::requireLogin();

// Variables de UI
$username = $_SESSION['username'] ?? 'Usuario';
$darkMode = (isset($_COOKIE['chilemon_darkmode']) && $_COOKIE['chilemon_darkmode'] === 'true');

// =============================================================================
// Nodos reales desde Asterisk vía AslRptService
// =============================================================================
$dbError    = null;
$nodos      = [];   // array de arrays con estructura esperada por dashboard.php
$estadisticas = [
    'total_nodos'    => 0,
    'nodos_online'   => 0,
    'nodos_idle'     => 0,
    'total_usuarios' => 0,
];

try {
    $svc    = new AslRptService();
    $raw    = $svc->nodes();
    $ids    = AslRptService::parseNodes($raw);

    // Construir estructura compatible con dashboard.php
    // Todos los nodos que retorna Asterisk están "online" (son enlaces activos)
    foreach ($ids as $nodeId) {
        $nodos[] = [
            'node_id'           => $nodeId,
            'alias'             => null,
            'status'            => 'online',
            'connection_status' => 'online',
            'signal'            => null,
            'users'             => 0,
            'minutes_ago'       => 0,
            'last_seen'         => date('Y-m-d H:i:s'),
        ];
    }

    $estadisticas['total_nodos']  = count($nodos);
    $estadisticas['nodos_online'] = count($nodos); // todos son online (vienen de Asterisk)
    $estadisticas['nodos_idle']   = 0;
    $estadisticas['total_usuarios'] = 0;

} catch (Throwable $e) {
    // Si Asterisk falla, mostrar error pero no romper el dashboard
    $dbError = 'Error al consultar Asterisk: ' . $e->getMessage();
    error_log('[index.php] AslRptService error: ' . $e->getMessage());
}

// =============================================================================
// System info (CPU, IP, temperatura, hostname, etc.)
// =============================================================================
$systemInfo = System::getSystemInfo();
$ipLists    = System::getIpLists();
$ipv4_list  = $ipLists['ipv4'];
$ipv6_list  = $ipLists['ipv6'];

// Render — la vista NO debe llamar session_start() ni require_once de config
require __DIR__ . '/views/dashboard.php';
