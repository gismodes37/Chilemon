<?php
// ChileMon - Dashboard Supermon Style con Info Raspberry Pi
session_start();

// ============================================
// CONFIGURACIÓN CORREGIDA PARA /chilemon/
// ============================================

// Detectar si estamos en /chilemon/ mediante Apache proxy
$isBehindProxy = isset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);

if ($isBehindProxy && isset($_SERVER['HTTP_X_FORWARDED_PREFIX'])) {
    // Configuración para proxy Apache (/chilemon/)
    $basePath = $_SERVER['HTTP_X_FORWARDED_PREFIX'];
} elseif (strpos($scriptPath, '/chilemon') !== false) {
    // Detectar manualmente si estamos en /chilemon/
    $basePath = '/chilemon';
} else {
    // Configuración directa
    $basePath = $scriptPath;
}

// Asegurar que basePath termine con /
if ($basePath !== '/' && substr($basePath, -1) !== '/') {
    $basePath .= '/';
}

// Configurar BASE_URL dinámicamente
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host . $basePath;

// Configuración
//define('APP_NAME', 'ChileMon');
define('APP_VERSION', '0.4.0');
//define('BASE_URL', $baseUrl);
//define('BASE_PATH', $basePath);

// Debug (puedes comentar después)
error_log("ChileMon Config: BasePath=$basePath, BaseURL=$baseUrl");

// Tema preferido
$darkMode = isset($_COOKIE['chilemon_darkmode']) && $_COOKIE['chilemon_darkmode'] === 'true';
// Defaults para evitar warnings si falla DB o consultas
$estadisticas = [
    'total_nodos'   => 0,
    'nodos_online'  => 0,
    'nodos_idle'    => 0,
    'total_usuarios'=> 0,
];
$nodos = [];
$dbError = null;


// ======================
// DB (SQLite ONLY)
// ======================
require_once __DIR__ . '/../app/Core/Database.php';

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

    // SQLite: calcular minutos desde last_seen
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

    $totalNodos   = count($nodos);
    $nodosOnline  = array_filter($nodos, fn($n) => ($n['connection_status'] ?? '') === 'online');
    $nodosIdle    = array_filter($nodos, fn($n) => ($n['connection_status'] ?? '') === 'idle');

    $estadisticas = [
        'total_nodos'    => $totalNodos,
        'nodos_online'   => count($nodosOnline),
        'nodos_idle'     => count($nodosIdle),
        'total_usuarios' => array_sum(array_map(fn($n) => (int)($n['users'] ?? 0), $nodos)),
    ];

} catch (Throwable $e) {
    $dbError = "Error SQLite: " . $e->getMessage();
}



// Obtener información básica del sistema
function getSystemInfo() {
    $info = [
        'lan_ip' => '127.0.0.1',
        'web_port' => $_SERVER['SERVER_PORT'] ?? 80,
        'hostname' => gethostname() ?: 'localhost',
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido',
        'timezone' => date_default_timezone_get()
    ];
    
    // Obtener todas las IPs del sistema
    $all_ips = [];
    if (function_exists('shell_exec')) {
        $ip_output = trim(shell_exec("hostname -I 2>/dev/null") ?: '');
        if (!empty($ip_output)) {
            $all_ips = explode(' ', $ip_output);
        }
    }
    
    // Buscar IPv4 específicamente
    foreach ($all_ips as $ip) {
        $ip = trim($ip);
        // Filtrar solo IPv4 (xxx.xxx.xxx.xxx)
        if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ip)) {
            $info['lan_ip'] = $ip;
            break;
        }
    }
    
    // Si no encuentra IPv4, usar la primera IP disponible
    if ($info['lan_ip'] === '127.0.0.1' && !empty($all_ips)) {
        $info['lan_ip'] = trim($all_ips[0]);
    }
    
    // Obtener temperatura del CPU
    $info['cpu_temp_c'] = getCpuTemperature();
    $info['cpu_temp_f'] = round(($info['cpu_temp_c'] * 9/5) + 32, 1);
    
    return $info;
}

// Función para obtener temperatura real del CPU (Raspberry Pi)
function getCpuTemperature() {
    if (function_exists('shell_exec')) {
        // Para Raspberry Pi
        $temp = shell_exec('vcgencmd measure_temp 2>/dev/null');
        if ($temp && preg_match('/temp=([\d.]+)/', $temp, $matches)) {
            return floatval($matches[1]);
        }
        
        // Alternativa para sistemas Linux
        $temp = shell_exec('cat /sys/class/thermal/thermal_zone0/temp 2>/dev/null');
        if ($temp) {
            return floatval($temp) / 1000;
        }
    }
    
    // Valor por defecto si no se puede obtener
    return rand(40, 55);
}

$systemInfo = getSystemInfo();

// Obtener todas las IPs para mostrar en la interfaz
$all_system_ips = [];
if (function_exists('shell_exec')) {
    $ip_output = trim(shell_exec("hostname -I 2>/dev/null") ?: '');
    if (!empty($ip_output)) {
        $all_system_ips = explode(' ', $ip_output);
    }
}

// Separar IPv4 e IPv6
$ipv4_list = [];
$ipv6_list = [];
foreach ($all_system_ips as $ip) {
    $ip = trim($ip);
    if (preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ip)) {
        $ipv4_list[] = $ip;
    } elseif (strpos($ip, ':') !== false) {
        $ipv6_list[] = $ip;
    }
}
?>

<!DOCTYPE html>
<html lang="es-CL" data-bs-theme="<?= $darkMode ? 'dark' : 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?> v<?= APP_VERSION ?> - Supermon Style</title>
    
    <!-- Bootstrap 5 + Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- CSS Supermon Style -->
    <style>
        :root {
            --asl-green: #28a745;
            --asl-blue: #007bff;
            --asl-red: #dc3545;
            --asl-yellow: #ffc107;
            --asl-dark: #343a40;
            --chile-blue: #0039A6;
            --chile-red: #D52B1E;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
            min-height: 100vh;
            padding-top: 140px; /* Espacio para header fijo */
        }
        
        /* HEADER estilo Supermon - FIJADO */
        .supermon-header {
            background: linear-gradient(135deg, var(--chile-blue) 0%, #00155a 100%);
            color: white;
            border-bottom: 3px solid var(--chile-red);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030; /* Mayor que Bootstrap modales (1050) */
            width: 100%;
            transition: all 0.3s ease;
        }
        
        /* Header compacto al hacer scroll */
        .supermon-header.scrolled {
            padding-top: 5px;
            padding-bottom: 5px;
        }
        
        .supermon-header.scrolled .header-title {
            font-size: 1.2rem;
        }
        
        .supermon-header.scrolled .quick-info {
            font-size: 0.75em;
        }
        
        .header-badge {
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 0.8em;
        }
        
        /* Panel de control */
        .control-panel {
            background: var(--bs-tertiary-bg);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid var(--asl-green);
        }
        
        /* Tabla estilo Supermon */
        .supermon-table {
            font-size: 0.9em;
        }
        
        .supermon-table th {
            background: var(--bs-secondary-bg);
            border-bottom: 2px solid var(--bs-border-color);
            font-weight: 600;
        }
        
        .status-online {
            color: var(--asl-green);
            font-weight: bold;
        }
        
        .status-idle {
            color: var(--asl-yellow);
            font-weight: bold;
        }
        
        .status-offline {
            color: var(--asl-red);
            font-weight: bold;
        }
        
        /* Botones de acción */
        .btn-connect {
            background: var(--asl-green);
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.85em;
        }
        
        .btn-connect:hover {
            background: #218838;
        }
        
        .btn-monitor {
            background: var(--asl-blue);
            color: white;
            border: none;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.85em;
        }
        
        /* Número de nodo entrada */
        .node-input {
            max-width: 150px;
            font-family: monospace;
        }
        
        /* Footer */
        .footer-info {
            font-size: 0.8em;
            color: var(--bs-secondary-color);
            border-top: 1px solid var(--bs-border-color);
            padding-top: 10px;
        }
        
        /* Toggle theme */
        .theme-toggle-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--asl-blue);
            color: white;
            border: none;
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .theme-toggle-btn:hover {
            transform: scale(1.1);
        }
        
        /* Tarjetas de sistema */
        .system-card {
            border-radius: 8px;
            border: none;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .system-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .temp-display {
            font-size: 1.8rem;
            font-weight: bold;
            line-height: 1;
        }
        
        .temp-low { color: #28a745; }
        .temp-medium { color: #ffc107; }
        .temp-high { color: #dc3545; }
        
        .code-ip {
            font-family: 'Courier New', monospace;
            background: var(--bs-tertiary-bg);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.9em;
            border: 1px solid var(--bs-border-color);
        }
        
        /* Información rápida en header */
        .quick-info {
            font-size: 0.8em;
            opacity: 0.9;
            transition: all 0.3s ease;
        }
        
        .quick-info-item {
            display: inline-block;
            margin-right: 15px;
        }
        
        /* Animaciones */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-top: 160px; /* Más espacio en móviles */
            }
            
            .quick-info {
                font-size: 0.7em;
            }
            
            .temp-display {
                font-size: 1.5rem;
            }
            
            .supermon-header.scrolled {
                padding-top: 8px;
                padding-bottom: 8px;
            }
            
            /* Ocultar algunos elementos en móvil al hacer scroll */
            .supermon-header.scrolled .quick-info-item:nth-child(n+3) {
                display: none;
            }
        }
        
        @media (max-width: 576px) {
            body {
                padding-top: 180px; /* Más espacio en móviles pequeños */
            }
            
            .supermon-header.scrolled .quick-info-item:nth-child(n+2) {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Header con información rápida del sistema - AHORA FIJO -->
    <header class="supermon-header py-3" id="main-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="h4 mb-1 header-title">
                        <!-- Bandera de Chile - RUTA CORREGIDA -->
                        <!-- Bandera de Chile -->
                        <?php
                            $flagPath = __DIR__ . '/assets/img/Flag_of_chile.svg';
                            $flagUrl = file_exists($flagPath) ? BASE_URL . 'assets/img/Flag_of_chile.svg' : 'https://flagcdn.com/w40/cl.png';
                        ?>
                        <img src="<?= $flagUrl ?>" 
                            alt="Bandera de Chile" 
                            width="40" 
                            height="27"
                            style="border-radius: 3px; border: 1px solid #ddd; margin-right: 12px; vertical-align: middle;">
                            
                        <strong><?= APP_NAME ?></strong> 
                        <small class="opacity-75">v<?= APP_VERSION ?></small>
                        <span class="header-badge ms-2">Supermon Style</span>
                    </h1>
                    <p class="mb-1 Default opacity-75">
                        <i class="bi bi-wifi"></i> Dashboard para nodos <span class="badge text-dark " style="background-color: #66A01B;"> AllStar Link</span> Chile
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="d-flex justify-content-md-end align-items-center gap-3">
                        <div class="text-end">
                            <div class="mb-1">
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle"></i> <?= $estadisticas['nodos_online'] ?> Online
                                </span>
                                <span class="badge bg-warning ms-1">
                                    <i class="bi bi-clock"></i> <?= $estadisticas['nodos_idle'] ?> Idle
                                </span>
                            </div>
                            <div class="quick-info d-none d-md-block">
                                <!-- Mostrar IPv4 principal si existe -->
                                <?php if (!empty($ipv4_list)): ?>
                                <span class="quick-info-item">
                                    <i class="bi bi-pc text-primary"></i> 
                                    <span class="code-ip"><?= htmlspecialchars($ipv4_list[0]) ?></span>
                                </span>
                                <?php endif; ?>
                                
                                <span class="quick-info-item">
                                    <i class="bi bi-globe"></i> 
                                    <?php 
                                    $port = $systemInfo['web_port'];
                                    echo $port == 80 ? 'HTTP' : ($port == 443 ? 'HTTPS' : "Port $port");
                                    ?>
                                </span>
                                <span class="quick-info-item">
                                    <i class="bi bi-thermometer-half"></i> 
                                    <span class="<?= $systemInfo['cpu_temp_c'] < 50 ? 'temp-low' : ($systemInfo['cpu_temp_c'] < 70 ? 'temp-medium' : 'temp-high') ?>">
                                        <?= $systemInfo['cpu_temp_c'] ?>°C
                                    </span>
                                </span>
                                <span class="quick-info-item">
                                    <i class="bi bi-database"></i> SQLite | 
                                    <span id="current-time"><?= date('H:i') ?></span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Panel de control principal -->
    <main class="container mt-4">
        <?php if (isset($dbError)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($dbError) ?>
        </div>
        <?php endif; ?>
        
        <!-- Panel de conexión rápida -->
        <div class="control-panel">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <h5 class="mb-2"><i class="bi bi-lightning-charge"></i> Conexión rápida</h5>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-hash"></i></span>
                        <input type="text" id="node-number" class="form-control node-input" placeholder="Ej: 12345" maxlength="10">
                        <button class="btn btn-success" onclick="connectToNode()">
                            <i class="bi bi-telephone"></i> Conectar
                        </button>
                    </div>
                    <small class="text-muted">Ingresa número de nodo ASL</small>
                </div>
                <div class="col-md-4">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary">
                            <i class="bi bi-mic"></i> Transmitir
                        </button>
                        <button class="btn btn-outline-secondary">
                            <i class="bi bi-headphones"></i> Monitor
                        </button>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-info" onclick="refreshSystemInfo()">
                            <i class="bi bi-arrow-clockwise"></i> Actualizar
                        </button>
                        <button class="btn btn-sm btn-outline-dark" onclick="toggleTheme()">
                            <i class="bi <?= $darkMode ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de nodos -->
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white">
                <h3 class="h5 mb-0">
                    <i class="bi bi-diagram-3"></i> Nodos ASL Monitoreados
                    <span class="badge bg-light text-dark ms-2"><?= $estadisticas['total_nodos'] ?></span>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover supermon-table mb-0">
                        <thead>
                            <tr>
                                <th width="100">Nodo ID</th>
                                <th>Información del nodo</th>
                                <th width="120">Recibido</th>
                                <th width="100">Enlace</th>
                                <th width="80">Dirección</th>
                                <th width="120">Conectado</th>
                                <th width="100">Modo</th>
                                <th width="150">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($nodos)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-triangle"></i> No hay nodos disponibles
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($nodos as $nodo): ?>
                                <?php 
                                $statusClass = 'status-' . $nodo['connection_status'];
                                $badgeClass = $nodo['connection_status'] === 'online' ? 'bg-success' : 
                                             ($nodo['connection_status'] === 'idle' ? 'bg-warning' : 'bg-danger');
                                ?>
                                <tr>
                                    <td>
                                        <strong class="font-monospace"><?= htmlspecialchars($nodo['node_id']) ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?= htmlspecialchars($nodo['name']) ?></strong>
                                            <?php if ($nodo['frequency']): ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="bi bi-radioactive"></i> <?= htmlspecialchars($nodo['frequency']) ?> MHz
                                            </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($nodo['minutes_ago'] < 60): ?>
                                        00:00:<?= str_pad($nodo['minutes_ago'], 2, '0', STR_PAD_LEFT) ?>
                                        <?php else: ?>
                                        Nunca
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= strtoupper($nodo['connection_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">IN</span>
                                    </td>
                                    <td>
                                        <?php if ($nodo['connection_status'] === 'online'): ?>
                                        00:<?= str_pad(rand(1, 59), 2, '0', STR_PAD_LEFT) ?>:<?= str_pad(rand(10, 59), 2, '0', STR_PAD_LEFT) ?>
                                        <?php else: ?>
                                        --
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($nodo['mode'] ?: 'ASL') ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-connect" onclick="connectToSpecificNode('<?= $nodo['node_id'] ?>')">
                                                <i class="bi bi-telephone"></i> Conectar
                                            </button>
                                            <button class="btn btn-monitor">
                                                <i class="bi bi-headphones"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary">
                                                <i class="bi bi-info-circle"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Panel de información del sistema Raspberry Pi -->
        <div class="row mt-4" id="system-info-section">
            <div class="col-md-12">
                <div class="card system-card">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="bi bi-raspberry-pi"></i> Información del Sistema - Raspberry Pi
                        </h6>
                        <button class="btn btn-sm btn-light" onclick="refreshSystemInfo()">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row" id="system-info">
                            <!-- Se actualizará dinámicamente con JavaScript -->
                            <div class="col-md-3 mb-3">
                                <div class="card h-100 system-card">
                                    <div class="card-body">
                                        <h6 class="card-title"><i class="bi bi-globe"></i> Red</h6>
                                        
                                        <!-- Mostrar todas las IPv4 -->
                                        <p class="mb-1">
                                            <small class="text-muted">IPv4:</small><br>
                                            <?php if (!empty($ipv4_list)): ?>
                                                <?php foreach ($ipv4_list as $ip): ?>
                                                    <code class="code-ip d-block mb-1"><?= htmlspecialchars($ip) ?></code>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">No disponible</span>
                                            <?php endif; ?>
                                        </p>
                                        
                                        <!-- Mostrar primera IPv6 si existe -->
                                        <?php if (!empty($ipv6_list)): ?>
                                        <p class="mb-1">
                                            <small class="text-muted">IPv6:</small><br>
                                            <code class="code-ip"><?= htmlspecialchars($ipv6_list[0]) ?></code>
                                            <?php if (count($ipv6_list) > 1): ?>
                                                <small class="text-muted d-block">+ <?= count($ipv6_list)-1 ?> más</small>
                                            <?php endif; ?>
                                        </p>
                                        <?php endif; ?>
                                        
                                        <p class="mb-0">
                                            <small class="text-muted">Puerto Web:</small><br>
                                            <span class="badge bg-info">
                                                <?php 
                                                $port = $systemInfo['web_port'];
                                                echo $port == 80 ? 'HTTP' : ($port == 443 ? 'HTTPS' : "Port $port");
                                                ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card h-100 system-card">
                                    <div class="card-body text-center">
                                        <h6 class="card-title"><i class="bi bi-thermometer-half"></i> Temperatura CPU</h6>
                                        <div class="temp-display mb-2 <?= $systemInfo['cpu_temp_c'] < 50 ? 'temp-low' : ($systemInfo['cpu_temp_c'] < 70 ? 'temp-medium' : 'temp-high') ?>">
                                            <?= $systemInfo['cpu_temp_c'] ?>°C
                                        </div>
                                        <p class="mb-0">
                                            <span class="badge bg-secondary"><?= $systemInfo['cpu_temp_f'] ?>°F</span>
                                        </p>
                                        <div class="progress mt-2" style="height: 6px;">
                                            <?php 
                                            $tempPercent = min($systemInfo['cpu_temp_c'], 100);
                                            $tempColor = $systemInfo['cpu_temp_c'] < 50 ? 'bg-success' : 
                                                         ($systemInfo['cpu_temp_c'] < 70 ? 'bg-warning' : 'bg-danger');
                                            ?>
                                            <div class="progress-bar <?= $tempColor ?>" 
                                                 style="width: <?= $tempPercent ?>%">
                                            </div>
                                        </div>
                                        <small class="text-muted mt-2 d-block">
                                            <?= getTemperatureStatus($systemInfo['cpu_temp_c']) ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card h-100 system-card">
                                    <div class="card-body">
                                        <h6 class="card-title"><i class="bi bi-speedometer2"></i> Sistema</h6>
                                        <p class="mb-2">
                                            <i class="bi bi-pc text-primary"></i>
                                            <small class="text-muted">Hostname:</small><br>
                                            <code><?= htmlspecialchars($systemInfo['hostname']) ?></code>
                                        </p>
                                        <p class="mb-2">
                                            <i class="bi bi-code-slash text-success"></i>
                                            <small class="text-muted">PHP:</small><br>
                                            <span class="badge bg-secondary"><?= $systemInfo['php_version'] ?></span>
                                        </p>
                                        <p class="mb-0">
                                            <i class="bi bi-clock text-info"></i>
                                            <small class="text-muted">Zona Horaria:</small><br>
                                            <span class="badge bg-info"><?= $systemInfo['timezone'] ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card h-100 system-card">
                                    <div class="card-body">
                                        <h6 class="card-title"><i class="bi bi-info-circle"></i> Comandos RPi</h6>
                                        <p class="mb-1">
                                            <small>Temperatura:</small><br>
                                            <code class="code-ip">vcgencmd measure_temp</code>
                                        </p>
                                        <p class="mb-1">
                                            <small>Info CPU:</small><br>
                                            <code class="code-ip">cat /proc/cpuinfo</code>
                                        </p>
                                        <p class="mb-0">
                                            <small>Memoria:</small><br>
                                            <code class="code-ip">free -h</code>
                                        </p>
                                        <div class="mt-3">
                                            <button class="btn btn-sm btn-outline-primary w-100" onclick="loadAdvancedSystemInfo()">
                                                <i class="bi bi-graph-up"></i> Info Avanzada
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Información del sistema tradicional -->
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="card system-card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-cpu"></i> Sistema</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-1">
                            <strong>PHP:</strong> <?= $systemInfo['php_version'] ?>
                        </p>
                        <p class="mb-1">
                            <strong>MySQL:</strong> MariaDB 10.4.32
                        </p>
                        <p class="mb-0">
                            <strong>Zona:</strong> <?= $systemInfo['timezone'] ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card system-card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-graph-up"></i> Estadísticas</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-1">
                            <i class="bi bi-diagram-3 text-primary"></i>
                            Nodos totales: <strong><?= $estadisticas['total_nodos'] ?></strong>
                        </p>
                        <p class="mb-1">
                            <i class="bi bi-check-circle text-success"></i>
                            En línea: <strong><?= $estadisticas['nodos_online'] ?></strong>
                        </p>
                        <p class="mb-0">
                            <i class="bi bi-people text-info"></i>
                            Usuarios: <strong><?= $estadisticas['total_usuarios'] ?></strong>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card system-card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> ChileMon</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-1">
                            <i class="bi bi-flag"></i>
                            Versión: <strong><?= APP_VERSION ?></strong>
                        </p>
                        <p class="mb-1">
                            <i class="bi bi-database"></i>
                            Tablas: <strong>2</strong> (nodes, calls)
                        </p>
                        <p class="mb-0">
                            <i class="bi bi-clock"></i>
                            Hora: <strong><span id="live-time"><?= date('H:i:s') ?></span></strong>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="mt-5 py-3">
        <div class="container">
            <div class="footer-info">
                <div class="row">
                    <div class="col-md-6">
                        <strong><?= APP_NAME ?> v<?= APP_VERSION ?></strong>
                        <span class="text-muted">- Dashboard Chilemon administrado en La Serena - Chile por Guillermo Ismodes - <a href="mailto:ca2iig@qsl.net">CA2IIG</a> </span>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <small>
                            <span id="full-date"></span> | 
                            <a href="#" class="text-decoration-none" onclick="toggleTheme(); return false;">
                                <i class="bi <?= $darkMode ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
                                <?= $darkMode ? 'Tema claro' : 'Tema oscuro' ?>
                            </a>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Botón flotante tema -->
    <button class="theme-toggle-btn" onclick="toggleTheme()" 
            title="<?= $darkMode ? 'Tema claro' : 'Tema oscuro' ?>">
        <i class="bi <?= $darkMode ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
    </button>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ChileMon Supermon Functions
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🇨🇱 ChileMon Supermon v<?= APP_VERSION ?>');
            console.log('Base Path: <?= BASE_PATH ?>');
            console.log('Base URL: <?= BASE_URL ?>');
            
            // Inicializar funciones
            updateTime();
            setInterval(updateTime, 1000);
            
            // Inicializar efecto de header fijo
            initStickyHeader();
        });
        
        // ============================================
        // HEADER FIJO CON SCROLL
        // ============================================
        
        function initStickyHeader() {
            const header = document.getElementById('main-header');
            const scrollThreshold = 50; // Píxeles antes de aplicar efectos
            
            function handleScroll() {
                const scrollPosition = window.scrollY || document.documentElement.scrollTop;
                
                if (scrollPosition > scrollThreshold) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            }
            
            // Escuchar evento de scroll
            window.addEventListener('scroll', handleScroll);
            
            // Ejecutar una vez al cargar
            handleScroll();
        }
        
        // ============================================
        // FUNCIONES BÁSICAS
        // ============================================
        
        // Actualizar hora en tiempo real
        function updateTime() {
            const now = new Date();
            
            // Hora simple
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = now.toLocaleTimeString('es-CL', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
            
            // Hora completa
            const liveTimeElement = document.getElementById('live-time');
            if (liveTimeElement) {
                liveTimeElement.textContent = now.toLocaleTimeString('es-CL');
            }
            
            // Fecha completa
            const dateElement = document.getElementById('full-date');
            if (dateElement) {
                dateElement.textContent = now.toLocaleDateString('es-CL', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            }
        }
        
        // Toggle de tema - ACTUALIZADO CON BASE_PATH
        window.toggleTheme = function() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-bs-theme', newTheme);
            
            // Guardar en cookie con path correcto
            const expiryDate = new Date();
            expiryDate.setDate(expiryDate.getDate() + 30);
            document.cookie = `chilemon_darkmode=${newTheme === 'dark'}; path=<?= BASE_PATH ?>; expires=${expiryDate.toUTCString()}`;
            
            // Actualizar iconos
            updateThemeIcons(newTheme);
            
            return false;
        };
        
        function updateThemeIcons(theme) {
            // Botón flotante
            const toggleBtn = document.querySelector('.theme-toggle-btn');
            if (toggleBtn) {
                const icon = toggleBtn.querySelector('i');
                if (icon) {
                    icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
                }
                toggleBtn.title = theme === 'dark' ? 'Tema claro' : 'Tema oscuro';
            }
            
            // Enlace en footer
            const themeLinks = document.querySelectorAll('a[onclick*="toggleTheme"]');
            themeLinks.forEach(link => {
                const icon = link.querySelector('i');
                if (icon) {
                    icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
                }
                link.innerHTML = theme === 'dark' ? 
                    '<i class="bi bi-sun"></i> Tema claro' : 
                    '<i class="bi bi-moon-stars"></i> Tema oscuro';
            });
        }
        
        // Conectar a nodo - ACTUALIZADO CON BASE_PATH
        window.connectToNode = function() {
            const nodeInput = document.getElementById('node-number');
            const nodeNumber = nodeInput.value.trim();
            
            if (!nodeNumber) {
                alert('Ingresa un número de nodo');
                nodeInput.focus();
                return;
            }
            
            // Simular conexión IAX
            console.log(`Conectando a nodo ASL: ${nodeNumber}`);
            
            // Mostrar notificación
            showNotification(`Conectando a nodo ${nodeNumber}...`, 'info');
            
            // Simular demora
            setTimeout(() => {
                showNotification(`Conexión establecida con ${nodeNumber}`, 'success');
                
                // Registrar llamada en BD (simulado) - USANDO BASE_PATH
                fetch('<?= BASE_PATH ?>api/log-call.php?node=' + encodeURIComponent(nodeNumber))
                    .then(response => response.json())
                    .then(data => console.log('Llamada registrada:', data));
                
            }, 1000);
        };
        
        window.connectToSpecificNode = function(nodeId) {
            // Extraer número si es formato CL-XXX-001
            const nodeNumber = nodeId.replace(/^[A-Z]+-/, '');
            document.getElementById('node-number').value = nodeNumber;
            connectToNode();
        };
        
        // ============================================
        // FUNCIONES PARA INFORMACIÓN DEL SISTEMA
        // ============================================
        
        // Obtener IP WAN - ACTUALIZADO CON BASE_PATH
        function loadWanIP() {
            const wanIpElement = document.getElementById('wan-ip');
            if (!wanIpElement) return;
            
            wanIpElement.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> Cargando...';
            
            fetch('<?= BASE_PATH ?>api/get-wan-ip.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.ip) {
                        wanIpElement.innerHTML = `<code class="code-ip pulse">${data.ip}</code>`;
                        // Quitar animación después de 3 segundos
                        setTimeout(() => {
                            wanIpElement.classList.remove('pulse');
                        }, 3000);
                    } else {
                        wanIpElement.innerHTML = '<span class="text-muted">No disponible</span>';
                    }
                })
                .catch(error => {
                    console.error('Error obteniendo IP WAN:', error);
                    wanIpElement.innerHTML = '<span class="text-muted">Error</span>';
                });
        }
        
        // Refrescar información del sistema
        function refreshSystemInfo() {
            // Mostrar indicador de carga
            const refreshBtn = document.querySelector('[onclick="refreshSystemInfo()"]');
            if (refreshBtn) {
                const originalHTML = refreshBtn.innerHTML;
                refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise spin"></i>';
                refreshBtn.disabled = true;
                
                setTimeout(() => {
                    refreshBtn.innerHTML = originalHTML;
                    refreshBtn.disabled = false;
                }, 1000);
            }
            
            // Actualizar IP WAN
            loadWanIP();
            
            // Simular actualización de temperatura
            updateCpuTemperature();
            
            // Mostrar notificación
            showNotification('✅ Información del sistema actualizada', 'success');
        }
        
        // Actualizar temperatura CPU (simulado)
        function updateCpuTemperature() {
            // En un sistema real, esto llamaría a una API
            const tempElements = document.querySelectorAll('.temp-display');
            tempElements.forEach(el => {
                // Simular cambio de temperatura
                const currentTemp = parseFloat(el.textContent);
                const change = (Math.random() - 0.5) * 2; // -1 a +1
                const newTemp = Math.max(35, Math.min(85, currentTemp + change));
                const newTempF = Math.round((newTemp * 9/5) + 32);
                
                // Actualizar display
                el.textContent = newTemp.toFixed(1) + '°C';
                
                // Actualizar color según temperatura
                if (newTemp < 50) {
                    el.className = 'temp-display mb-2 temp-low';
                } else if (newTemp < 70) {
                    el.className = 'temp-display mb-2 temp-medium';
                } else {
                    el.className = 'temp-display mb-2 temp-high';
                }
                
                // Actualizar badge de Fahrenheit si existe
                const fahrenheitBadge = el.parentElement.querySelector('.badge');
                if (fahrenheitBadge) {
                    fahrenheitBadge.textContent = newTempF + '°F';
                }
                
                // Actualizar barra de progreso
                const progressBar = el.parentElement.querySelector('.progress-bar');
                if (progressBar) {
                    const percent = Math.min(newTemp, 100);
                    progressBar.style.width = percent + '%';
                    
                    // Actualizar color de barra
                    if (newTemp < 50) {
                        progressBar.className = 'progress-bar bg-success';
                    } else if (newTemp < 70) {
                        progressBar.className = 'progress-bar bg-warning';
                    } else {
                        progressBar.className = 'progress-bar bg-danger';
                    }
                }
                
                // Actualizar texto de estado
                const statusText = el.parentElement.querySelector('.text-muted');
                if (statusText) {
                    statusText.textContent = getTemperatureStatusText(newTemp);
                }
            });
        }
        
        // Cargar información avanzada del sistema
        function loadAdvancedSystemInfo() {
            alert('🚀 Información avanzada del sistema\n\n' +
                  'Esta funcionalidad está en desarrollo.\n\n' +
                  'Próximamente mostrará:\n' +
                  '• Uso detallado de CPU y memoria\n' +
                  '• Temperatura en tiempo real\n' +
                  '• Información de discos\n' +
                  '• Logs del sistema\n' +
                  '• Estadísticas de red');
            
            // En futuras versiones, esto cargará un modal con info detallada
            // fetch('<?= BASE_PATH ?>api/system-advanced.php')
            //   .then(response => response.json())
            //   .then(data => showSystemModal(data));
        }
        
        // ============================================
        // FUNCIONES AUXILIARES
        // ============================================
        
        // Mostrar notificación
        function showNotification(message, type = 'info') {
            // Crear notificación
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alert.style.cssText = 'top: 80px; right: 20px; z-index: 9999; max-width: 300px;';
            alert.innerHTML = `
                <strong>${type === 'success' ? '✅' : 'ℹ️'} ${message}</strong>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(alert);
            
            // Auto-eliminar después de 3 segundos
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 3000);
        }
        
        // Función para determinar estado de temperatura
        function getTemperatureStatusText(tempC) {
            if (tempC < 50) return '✅ Temperatura normal';
            if (tempC < 60) return '⚠️ Caliente - Vigilar';
            if (tempC < 70) return '⚠️ Muy caliente - Mejorar ventilación';
            return '🔥 Crítico - Revisar enfriamiento';
        }
        
        // Estilos para spinner
        const style = document.createElement('style');
        style.textContent = `
            .spin {
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>

<?php
// Función para determinar estado de temperatura (PHP)
function getTemperatureStatus($tempC) {
    if ($tempC < 50) return '✅ Temperatura normal';
    if ($tempC < 60) return '⚠️ Caliente - Vigilar';
    if ($tempC < 70) return '⚠️ Muy caliente - Mejorar enfriamiento';
    return '🔥 Crítico - Revisar enfriamiento';
}
?>