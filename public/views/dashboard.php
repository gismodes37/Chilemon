<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------
 * dashboard.php
 * ---------------------------------------------------------
 * Vista principal del dashboard ChileMon.
 *
 * Responsabilidades:
 * - Renderizar nodos monitoreados
 * - Mostrar actividad reciente
 * - Mostrar información del sistema
 *
 * Importante:
 * - NO inicia sesión aquí; eso ya viene resuelto desde index.php
 * - NO debe romper si faltan campos opcionales en la tabla nodes
 * - Toma variables preparadas desde public/index.php
 */

require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Core/NodeLogger.php';

use App\Core\NodeLogger;

/**
 * ---------------------------------------------------------
 * Actividad reciente
 * ---------------------------------------------------------
 */
$recentEvents = [];

try {
    $recentEvents = NodeLogger::latest(15);
    if (!is_array($recentEvents)) {
        $recentEvents = [];
    }
} catch (\Throwable $e) {
    $recentEvents = [];
}

/**
 * ---------------------------------------------------------
 * Valores defensivos
 * ---------------------------------------------------------
 */
$ipv4_list    = $ipv4_list ?? [];
$ipv6_list    = $ipv6_list ?? [];
$nodos        = $nodos ?? [];
$dbError      = $dbError ?? null;
$darkMode     = $darkMode ?? true;
$estadisticas = $estadisticas ?? [
    'total_nodos'    => 0,
    'nodos_online'   => 0,
    'nodos_idle'     => 0,
    'total_usuarios' => 0,
];
$systemInfo = $systemInfo ?? [
    'web_port'     => 80,
    'cpu_temp_c'   => 0,
    'cpu_temp_f'   => 32,
    'hostname'     => 'desconocido',
    'php_version'  => PHP_VERSION,
    'timezone'     => date_default_timezone_get(),
];

?>

<?php require __DIR__ . '/partials/head.php'; ?>
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="container mt-4">

    <?php if (!empty($dbError)): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle"></i>
            <?= htmlspecialchars((string)$dbError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="acceso-rapido">
        <div class="row align-items-center">
            <div class="col-12">
                <h5 class="mb-3"><i class="bi bi-grid-3x3-gap"></i> Accesos rápidos</h5>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-custom" onclick="window.open('https://www.allstarlink.org/', '_blank')">
                        <i class="bi bi-globe"></i> AllStarLink !
                    </button>
                    <button class="btn btn-outline-custom" onclick="window.open('https://stats.allstarlink.org/maps/allstarUSAMap.html', '_blank')">
                        <i class="bi bi-globe"></i> AllStar Maps !
                    </button>
                    <button class="btn btn-outline-custom" onclick="window.open('https://wiki.allstarlink.org/wiki/Category:How_to', '_blank')">
                        <i class="bi bi-globe"></i> AllStar How To
                    </button>
                    <button class="btn btn-outline-custom" onclick="window.open('https://wiki.allstarlink.org/wiki/Main_Page', '_blank')">
                        <i class="bi bi-globe"></i> AllStar Wiki
                    </button>
                    <button class="btn btn-outline-warning" onclick="window.open('https://gismodes37.github.io/Chilemon/', '_blank')">
                        <i class="bi bi-globe"></i> Web Site Chilemon
                    </button>
                    <button class="btn btn-outline-warning" onclick="window.open('https://github.com/gismodes37/Chilemon', '_blank')">
                        <i class="bi bi-github"></i> Repositorio Chilemon
                    </button>
                    <button class="btn btn-outline-warning" onclick="window.open('#', '_blank')">
                        <i class="bi bi-github"></i> Manual / Usuario Chilemon
                    </button>
                    <button class="btn btn-outline-primary" onclick="window.open('https://github.com/gismodes37/Chilemon/blob/main/docs/soporte.md', '_blank')">
                        <i class="bi bi-globe"></i> Soporte
                    </button>
                    <button class="btn btn-outline-primary" onclick="window.open('https://www.qsl.net/ca2iig/', '_blank')">
                        <i class="bi bi-globe"></i> Web Site Desarrollador
                    </button>
                    <button class="btn btn-outline-danger" id="btn-restart-asterisk"
                        onclick="confirmarReinicio('Asterisk', 'restart-asterisk')"
                        title="Reiniciar el servicio Asterisk">
                        <i class="bi bi-arrow-repeat"></i> Reiniciar Asterisk
                    </button>
                    <button class="btn btn-outline-danger" id="btn-restart-apache"
                        onclick="confirmarReinicio('Apache', 'restart-apache')"
                        title="Reiniciar el servicio Apache">
                        <i class="bi bi-arrow-repeat"></i> Reiniciar Apache
                    </button>
                    <button class="btn btn-outline-danger" id="btn-power-node"
                        onclick="confirmarReinicio('Nodo (APAGAR)', 'poweroff')"
                        title="Apagar el nodo">
                        <i class="bi bi-power"></i> Apagar Nodo
                    </button>
                </div>
            </div>
        </div>
    </div>

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

    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h3 class="h5 mb-0">
                <i class="bi bi-diagram-3"></i> Nodos ASL Monitoreados
                <span class="badge bg-light text-dark ms-2" id="total-nodes-badge">
                    <?= (int)($estadisticas['total_nodos'] ?? count($nodos)) ?>
                </span>
            </h3>
            <div class="input-group" style="max-width: 250px;">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="node-search-filter" class="form-control border-start-0 ps-0" placeholder="Filtrar nodos...">
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover supermon-table mb-0">
                    <thead>
                        <tr>
                            <th width="100">Nodo ID</th>
                            <th>Información del nodo</th>
                            <th width="120">Recibido</th>
                            <th width="110">Actividad</th>
                            <th width="100">Enlace</th>
                            <th width="80">Dirección</th>
                            <th width="120">Conectado</th>
                            <th width="100">Modo</th>
                            <th width="150">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="nodes-table-body">
                        <?php if (empty($nodos)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    <i class="bi bi-exclamation-triangle"></i> No hay nodos disponibles
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($nodos as $nodo): ?>
                                <?php
                                $nodeId         = (string)($nodo['node'] ?? $nodo['node_id'] ?? '');
                                $nodeInfo       = (string)($nodo['info'] ?? $nodo['name'] ?? ('Nodo ' . $nodeId));
                                $isFav          = (bool)($nodo['is_favorite'] ?? false);
                                $receivedText   = (string)($nodo['received'] ?? '--');
                                $directionText  = (string)($nodo['direction'] ?? '');
                                $connectedText  = (string)($nodo['connected'] ?? '--');
                                $modeText       = (string)($nodo['mode'] ?? 'ASL');
                                $isOnline       = (bool)($nodo['online'] ?? false);
                                $visibilityType = (string)($nodo['visibility_type'] ?? '');
                                $remoteVisible  = $nodo['remote_visible'] ?? [];
                                $remoteCount    = (int)($nodo['remote_count'] ?? 0);
                                $canShowRemote  = (bool)($nodo['can_show_remote'] ?? false);
                                $remoteScope    = (string)($nodo['remote_scope'] ?? 'global');

                                if ($visibilityType === 'direct') {
                                    $badgeClass = 'bg-success';
                                    $linkLabel  = 'DIRECTO';
                                } elseif ($visibilityType === 'visible') {
                                    $badgeClass = 'bg-info';
                                    $linkLabel  = 'VISIBLE';
                                } else {
                                    $badgeClass = 'bg-secondary';
                                    $linkLabel  = 'DESCONOCIDO';
                                }

                                if ($receivedText === '') {
                                    $receivedText = '--';
                                }

                                if (!$isOnline && $visibilityType !== 'direct') {
                                    $directionText = '';
                                    $connectedText = '--';
                                }
                                ?>
                                <tr
                                id="node-row-<?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= $visibilityType === 'direct' ? 'node-row-direct' : 'node-row-visible' ?>">
                                    <td>
                                        <?php if ($canShowRemote): ?>
                                            <button
                                                type="button"
                                                class="btn btn-link p-0 text-decoration-none fw-bold font-monospace"
                                                onclick='openRemoteNodesModal(
                                                    "<?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>",
                                                    <?= json_encode(array_values($remoteVisible), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                                                    <?= json_encode($remoteScope, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                                                )'
                                                title="<?= $remoteScope === 'global' ? 'Ver topología global visible (mezcla varios enlaces)' : 'Ver nodos visibles en la red remota' ?>">

                                                <?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>
                                            </button>

                                            <?php if ($remoteCount > 0): ?>
                                             <small class="text-info ms-1">(<?= $remoteCount ?>)</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="fw-bold font-monospace">
                                                <?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars($nodeInfo, ENT_QUOTES, 'UTF-8') ?></td>

                                    <td><?= htmlspecialchars($receivedText, ENT_QUOTES, 'UTF-8') ?></td>

                                    <td>
                                        <span class="badge badge-activity-idle">
                                            <span class="activity-dot"></span> Idle
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= htmlspecialchars($linkLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if ($directionText !== ''): ?>
                                            <span class="badge bg-info">
                                                <?= htmlspecialchars($directionText, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">--</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars($connectedText, ENT_QUOTES, 'UTF-8') ?></td>

                                    <td>
                                        <span class="badge <?= $modeText === 'EchoLink' ? 'bg-purple' : 'bg-secondary' ?>">
                                            <?= htmlspecialchars($modeText, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-connect"
                                                onclick="connectToSpecificNode('<?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="bi bi-telephone"></i> Conectar
                                            </button>

                                            <button class="btn btn-outline-danger"
                                                onclick="disconnectFromNodeConfirm('<?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="bi bi-telephone-x"></i> Desconectar
                                            </button>

                                            <button class="btn btn-outline-warning"
                                                onclick="deleteNodeConfirm('<?= htmlspecialchars($nodeId, ENT_QUOTES, 'UTF-8') ?>')">
                                                <i class="bi bi-trash"></i> Eliminar
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

    <div class="card shadow-sm mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-activity"></i> Actividad reciente</h6>
            <span class="text-muted small">
                <?= !empty($recentEvents) ? 'Últimos ' . count($recentEvents) : 'Sin eventos' ?>
            </span>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 180px;">Fecha</th>
                            <th style="width: 100px;">Nodo</th>
                            <th style="width: 140px;">Evento</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentEvents)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-3 text-muted">
                                    <i class="bi bi-info-circle"></i> Aún no hay actividad registrada.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentEvents as $ev): ?>
                                <?php
                                $createdAt = (string)($ev['created_at'] ?? '');
                                $nodeNum   = (string)($ev['node_number'] ?? '');
                                $type      = (string)($ev['event_type'] ?? '');
                                $details   = (string)($ev['details'] ?? '');

                                $badge = 'bg-secondary';
                                if ($type === 'connect') {
                                    $badge = 'bg-success';
                                } elseif ($type === 'disconnect') {
                                    $badge = 'bg-danger';
                                } elseif (str_starts_with($type, 'favorite')) {
                                    $badge = 'bg-warning';
                                }
                                ?>
                                <tr>
                                    <td class="text-muted"><?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($nodeNum, ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge <?= $badge ?>">
                                            <?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($details, ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
                        <div class="col-md-3 mb-3">
                            <div class="card h-100 system-card">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="bi bi-globe"></i> Red</h6>

                                    <p class="mb-1">
                                        <small class="text-muted">IPv4:</small><br>
                                        <?php if (!empty($ipv4_list)): ?>
                                            <?php foreach ($ipv4_list as $ip): ?>
                                                <code class="code-ip d-block mb-1"><?= htmlspecialchars((string)$ip, ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No disponible</span>
                                        <?php endif; ?>
                                    </p>

                                    <?php if (!empty($ipv6_list)): ?>
                                        <p class="mb-1">
                                            <small class="text-muted">IPv6:</small><br>
                                            <code class="code-ip"><?= htmlspecialchars((string)$ipv6_list[0], ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php if (count($ipv6_list) > 1): ?>
                                                <small class="text-muted d-block">+ <?= count($ipv6_list) - 1 ?> más</small>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>

                                    <p class="mb-0">
                                        <small class="text-muted">Puerto Web:</small><br>
                                        <span class="badge bg-info">
                                            <?php
                                            $port = (int)($systemInfo['web_port'] ?? 80);
                                            echo $port === 80 ? 'HTTP' : ($port === 443 ? 'HTTPS' : "Port {$port}");
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
                                    <div class="temp-display mb-2 <?= ((float)$systemInfo['cpu_temp_c'] < 50) ? 'temp-low' : (((float)$systemInfo['cpu_temp_c'] < 70) ? 'temp-medium' : 'temp-high') ?>">
                                        <?= htmlspecialchars((string)$systemInfo['cpu_temp_c'], ENT_QUOTES, 'UTF-8') ?>°C
                                    </div>
                                    <p class="mb-0">
                                        <span class="badge bg-secondary">
                                            <?= htmlspecialchars((string)$systemInfo['cpu_temp_f'], ENT_QUOTES, 'UTF-8') ?>°F
                                        </span>
                                    </p>
                                    <div class="progress mt-2" style="height: 6px;">
                                        <?php
                                        $tempC = (float)($systemInfo['cpu_temp_c'] ?? 0);
                                        $tempPercent = min(max($tempC, 0), 100);
                                        $tempColor = $tempC < 50 ? 'bg-success' : ($tempC < 70 ? 'bg-warning' : 'bg-danger');
                                        ?>
                                        <div class="progress-bar <?= $tempColor ?>" style="width: <?= $tempPercent ?>%"></div>
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
                                        <code><?= htmlspecialchars((string)$systemInfo['hostname'], ENT_QUOTES, 'UTF-8') ?></code>
                                    </p>
                                    <p class="mb-2">
                                        <i class="bi bi-code-slash text-success"></i>
                                        <small class="text-muted">PHP:</small><br>
                                        <span class="badge bg-secondary"><?= htmlspecialchars((string)$systemInfo['php_version'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </p>
                                    <p class="mb-0">
                                        <i class="bi bi-clock text-info"></i>
                                        <small class="text-muted">Zona Horaria:</small><br>
                                        <span class="badge bg-info"><?= htmlspecialchars((string)$systemInfo['timezone'], ENT_QUOTES, 'UTF-8') ?></span>
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
                    </div><!-- /row -->
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-md-4">
            <div class="card system-card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-cpu"></i> Sistema</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1">
                        <strong>PHP:</strong> <?= htmlspecialchars((string)$systemInfo['php_version'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <p class="mb-1">
                        <strong>SQLite:</strong> SQLite 3
                    </p>
                    <p class="mb-0">
                        <strong>Zona:</strong> <?= htmlspecialchars((string)$systemInfo['timezone'], ENT_QUOTES, 'UTF-8') ?>
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
                        Nodos totales: <strong><?= (int)($estadisticas['total_nodos'] ?? count($nodos)) ?></strong>
                    </p>
                    <p class="mb-1">
                        <i class="bi bi-check-circle text-success"></i>
                        En línea: <strong><?= (int)($estadisticas['nodos_online'] ?? 0) ?></strong>
                    </p>
                    <p class="mb-0">
                        <i class="bi bi-people text-info"></i>
                        Usuarios: <strong><?= (int)($estadisticas['total_usuarios'] ?? 0) ?></strong>
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
                        Versión: <strong><?= htmlspecialchars((string)APP_VERSION, ENT_QUOTES, 'UTF-8') ?></strong>
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

    <div class="modal fade" id="favoritesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-star-fill text-warning"></i>
                        Mis nodos favoritos
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <form id="favForm" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Nodo</label>
                            <input class="form-control" name="node_id" id="fav_node_id" placeholder="Ej: 2002" maxlength="10" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Alias</label>
                            <input class="form-control" name="alias" id="fav_alias" placeholder="Ej: Serena Link" maxlength="60">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Descripción</label>
                            <input class="form-control" name="description" id="fav_desc" placeholder="Ej: Nodo regional 24/7" maxlength="500">
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-save"></i> Guardar
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="fav_clear">
                                Limpiar
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Mis nodos</h6>
                        <button class="btn btn-sm btn-outline-primary" id="fav_reload">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Nodo</th>
                                    <th>Alias</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="fav_tbody">
                                <tr><td colspan="4" class="text-muted">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <small class="text-muted">
                        Al hacer clic en “Conectar” se pedirá confirmación y volverás al dashboard.
                    </small>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade modal-chilemon-danger" id="modalReinicio" tabindex="-1" aria-labelledby="modalReicioLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalReicioLabel">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="reinicioTitulo">Confirmar acción</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <i id="reinicioIcono" class="bi bi-exclamation-octagon text-danger" style="font-size: 3.5rem; opacity: 0.9;"></i>
                </div>
                <p class="mb-1 opacity-75 fs-5">¿Estás seguro que deseas realizar esta acción sobre</p>
                <p class="fw-bold fs-3 mb-2" id="modal-servicio-nombre">—</p>
                <p class="small opacity-50">Esta acción es sensible y puede interrumpir la operatividad del nodo.</p>
            </div>
            <div class="modal-footer justify-content-center gap-2">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Cancelar
                </button>
                <button type="button" class="btn btn-danger px-4" id="btn-confirmar-reinicio">
                    <i class="bi bi-arrow-clockwise"></i> Sí, reiniciar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarReinicio(servicio, action) {
    document.getElementById('modal-servicio-nombre').textContent = servicio;
    
    const reinicioIcono = document.getElementById('reinicioIcono');
    const reinicioTitulo = document.getElementById('reinicioTitulo');
    const btnConfirmar = document.getElementById('btn-confirmar-reinicio');

    const isPoweroff = action === 'poweroff';
    const isApacheRestart = action === 'restart-apache';

    // Personalizar según acción
    if (isPoweroff) {
        reinicioIcono.className = "bi bi-power text-danger";
        reinicioTitulo.textContent = "Confirmar Apagado";
        btnConfirmar.className = 'btn btn-danger px-4';
        btnConfirmar.innerHTML = '<i class="bi bi-power"></i> Sí, apagar';
    } else {
        reinicioIcono.className = "bi bi-arrow-repeat text-warning";
        reinicioTitulo.textContent = "Confirmar Reinicio";
        btnConfirmar.className = 'btn btn-warning px-4';
        btnConfirmar.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Sí, reiniciar';
    }

    btnConfirmar.onclick = async function () {
        btnConfirmar.disabled = true;
        btnConfirmar.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando...';

        try {
            await postForm('api/system_action.php', { action: action });

            // Cerrar modal y mostrar resultado
            bootstrap.Modal.getInstance(document.getElementById('modalReinicio')).hide();

            if (isPoweroff) {
                alert('✅ Orden de apagado enviada. El nodo se apagará en unos segundos.');
            } else {
                alert('✅ ' + servicio + ' reiniciado correctamente.');
                setTimeout(() => { window.location.reload(); }, 3000);
            }
        } catch (err) {
            // Cerrar el modal siempre
            try { bootstrap.Modal.getInstance(document.getElementById('modalReinicio')).hide(); } catch(e) {}

            // Cuando reiniciamos Apache o apagamos el nodo, el servidor se cae
            // ANTES de enviar la respuesta HTTP. El navegador reporta "Failed to fetch"
            // pero eso significa que la acción SÍ se ejecutó correctamente.
            if ((isApacheRestart || isPoweroff) && err.message !== 'Unauthorized') {
                if (isPoweroff) {
                    alert('✅ Orden de apagado enviada. El nodo se apagará en unos segundos.');
                } else {
                    alert('✅ Apache se está reiniciando. La página se recargará en unos segundos...');
                    setTimeout(() => { window.location.reload(); }, 5000);
                }
            } else if (err.message !== 'Unauthorized') {
                alert('❌ Error: ' + err.message);
            }
        } finally {
            btnConfirmar.disabled = false;
            btnConfirmar.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Sí, reiniciar';
        }
    };

    new bootstrap.Modal(document.getElementById('modalReinicio')).show();
}
</script>

<div class="modal fade modal-chilemon-info" id="remoteNodesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-diagram-3-fill text-info"></i>
                    Red remota visible — Nodo <span id="remoteNodesModalNode" class="font-monospace text-info">--</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted mb-3" id="remoteNodesModalSummary">
                    Sin datos.
                </p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Nodo</th>
                                <th>Relación</th>
                            </tr>
                        </thead>
                        <tbody id="remoteNodesModalBody">
                            <tr>
                                <td colspan="2" class="text-muted">Sin nodos visibles.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>


<?php
require __DIR__ . '/partials/footer.php';
require __DIR__ . '/partials/scripts.php';
?>