<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Helpers/System.php';

use App\Auth\Auth;
use App\Helpers\System;

Auth::requireLogin();

define('APP_VERSION', '0.4.0');

$username = $_SESSION['username'] ?? 'Usuario';
$darkMode = (isset($_COOKIE['chilemon_darkmode']) && $_COOKIE['chilemon_darkmode'] === 'true');

// ======================
// DB (SQLite ONLY)
// ======================
$dbError = null;
$nodos = [];
$estadisticas = [
    'total_nodos'    => 0,
    'nodos_online'   => 0,
    'nodos_idle'     => 0,
    'total_usuarios' => 0,
];

try {
    $db = \App\Core\Database::getInstance();

    $stmt = $db->query("
        SELECT *,
        CAST((julianday('now') - julianday(last_seen)) * 24 * 60 AS INTEGER) AS minutes_ago,
        CASE
            WHEN last_seen IS NULL THEN 'offline'
            WHEN (julianday('now') - julianday(last_seen)) * 24 * 60 <= 5  THEN 'online'
            WHEN (julianday('now') - julianday(last_seen)) * 24 * 60 <= 15 THEN 'idle'
            ELSE 'offline'
        END AS connection_status
        FROM nodes
        ORDER BY
        CASE connection_status
            WHEN 'online' THEN 1
            WHEN 'idle' THEN 2
            ELSE 3
        END,
        last_seen DESC
    ");

    $nodos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nodosOnline = array_filter($nodos, fn($n) => (($n['connection_status'] ?? '') === 'online'));
    $nodosIdle   = array_filter($nodos, fn($n) => (($n['connection_status'] ?? '') === 'idle'));

    $estadisticas['total_nodos']  = count($nodos);
    $estadisticas['nodos_online'] = count($nodosOnline);
    $estadisticas['nodos_idle']   = count($nodosIdle);

    $estadisticas['total_usuarios'] = array_sum(
        array_map(fn($n) => (int)($n['users'] ?? 0), $nodos)
    );

} catch (Throwable $e) {
    $dbError = 'Error SQLite: ' . $e->getMessage();
}

// ======================
// System info
// ======================
$systemInfo = System::getSystemInfo();
$ipLists = System::getIpLists();
$ipv4_list = $ipLists['ipv4'];
$ipv6_list = $ipLists['ipv6'];

// Render (la vista NO debe hacer session_start ni require_once)
require __DIR__ . '/views/dashboard.php';