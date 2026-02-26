<?php
// aquí ya deberían venir definidas desde index.php:
// $estadisticas, $systemInfo, $ipv4_list, $username, $darkMode, APP_VERSION, BASE_URL, APP_NAME

// Si falta ipv6_list, define vacío para evitar warnings
$ipv6_list = $ipv6_list ?? [];
?>

<?php require __DIR__ . '/partials/head.php'; ?>
<?php require __DIR__ . '/partials/header.php'; ?>

<!-- Panel de control principal -->
    <main class="container mt-4">
        <?php if (!empty($dbError)): ?>
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
                        <button class="btn btn-sm btn-outline-info" onclick="toggleTheme()">
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
                                        00:<?= str_pad((string) rand(1, 59), 2, '0', STR_PAD_LEFT) ?>:<?= str_pad((string) rand(10, 59), 2, '0', STR_PAD_LEFT) ?>
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
                                        <?php if (!empty($ipv6_list ?? [])): ?>
                                        <p class="mb-1">
                                            <small class="text-muted">IPv6:</small><br>
                                            <code class="code-ip"><?= htmlspecialchars(($ipv6_list ?? [])[0]) ?></code>
                                            <?php if (count($ipv6_list ?? []) > 1): ?>
                                                <small class="text-muted d-block">+ <?= count($ipv6_list ?? [])-1 ?> más</small>
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

<?php 
require __DIR__ . '/partials/footer.php'; 
require __DIR__ . '/partials/scripts.php';
?>