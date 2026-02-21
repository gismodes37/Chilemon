<?php
// Activar errores para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}


// ============================================
// 1. CARGAR CONFIGURACIÓN CON RUTA CORRECTA
// ============================================

$config = null;
// RUTA CORRECTA: desde public/ necesitas subir 2 niveles para llegar a config/
$configPath = __DIR__ . '/../../config/database.php';

echo "<!-- Debug: Ruta config buscada: " . htmlspecialchars($configPath) . " -->";

// Verificar si el archivo existe
if (!file_exists($configPath)) {
    // Intentar ruta alternativa
    $configPathAlt = __DIR__ . '/../config/database.php';
    if (file_exists($configPathAlt)) {
        $configPath = $configPathAlt;
        echo "<!-- Debug: Usando ruta alternativa -->";
    } else {
        die("<h1>❌ ERROR CRÍTICO - ChileMon Admin</h1>
            <p>No se encuentra el archivo de configuración database.php</p>
            <p><strong>Rutas probadas:</strong></p>
            <ul>
                <li>" . htmlspecialchars(__DIR__ . '/../../config/database.php') . "</li>
                <li>" . htmlspecialchars(__DIR__ . '/../config/database.php') . "</li>
            </ul>
            <p><strong>Solución:</strong> Asegúrate que el archivo existe en config/database.php</p>
            <p><a href='debug-path.php'>👉 Ver diagnóstico completo</a></p>");
    }
}

echo "<!-- Debug: Archivo encontrado en: " . htmlspecialchars($configPath) . " -->";

// Cargar configuración
try {
    $config = require $configPath;
    
    // Validar configuración mínima
    if (!is_array($config)) {
        die("<h1>❌ ERROR: Configuración no es un array</h1>
            <p>El archivo database.php debe retornar un array.</p>");
    }
    
    // Asegurar charset compatible
    if (isset($config['charset']) && $config['charset'] === 'utf8mb4') {
        $config['charset'] = 'utf8'; // Forzar compatibilidad con XAMPP
    }
    
    echo "<!-- Debug: Config cargada correctamente -->";
    
} catch (Exception $e) {
    die("<h1>❌ ERROR AL CARGAR CONFIGURACIÓN</h1>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>");
}

// ============================================
// 2. AUTENTICACIÓN
// ============================================

$valid_passwords = ["admin" => "chilemon2024"];
$valid_users = array_keys($valid_passwords);

$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';

// Si se solicita logout
if (isset($_GET['logout'])) {
    header('WWW-Authenticate: Basic realm="ChileMon Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<h1>Sesión cerrada</h1><p>Vuelve a iniciar sesión.</p>';
    exit;
}

$validated = (in_array($user, $valid_users) && $pass === $valid_passwords[$user]);

if (!$validated) {
    header('WWW-Authenticate: Basic realm="ChileMon Admin"');
    header('HTTP/1.0 401 Unauthorized');
    die('<h1>Acceso no autorizado</h1>
        <p>Usuario/contraseña incorrectos.</p>
        <p><strong>Credenciales de prueba:</strong></p>
        <ul>
            <li>Usuario: <code>admin</code></li>
            <li>Contraseña: <code>chilemon2024</code></li>
        </ul>');
}

// ============================================
// 3. CONEXIÓN A BASE DE DATOS
// ============================================

$db = null;
$dbError = null;
$stats = [];
$nodos = [];
$llamadas = [];

try {
    // Configuración de conexión
    $host = $config['host'] ?? 'localhost';
    $port = $config['port'] ?? '3306';
    $database = $config['database'] ?? 'chilemon_db';
    $username = $config['username'] ?? 'root';
    $password = $config['password'] ?? '';
    $charset = $config['charset'] ?? 'utf8';
    
    echo "<!-- Debug: Conectando a $host:$port, DB: $database -->";
    
    $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=$charset";
    $db = new PDO($dsn, $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    echo "<!-- Debug: Conexión exitosa -->";
    
    // ============================================
    // 4. OBTENER ESTADÍSTICAS
    // ============================================
    
    // Total de nodos
    $stmt = $db->query("SELECT COUNT(*) as total FROM nodes");
    $stats['total_nodos'] = $stmt->fetch()['total'];
    
    // Nodos online
    $stmt = $db->query("SELECT COUNT(*) as online FROM nodes WHERE status = 'online'");
    $stats['nodos_online'] = $stmt->fetch()['online'];
    
    // Llamadas hoy
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM calls WHERE DATE(start_time) = CURDATE()");
    $stmt->execute();
    $stats['llamadas_hoy'] = $stmt->fetch()['total'];
    
    // Total de llamadas
    $stmt = $db->query("SELECT COUNT(*) as total FROM calls");
    $stats['total_llamadas'] = $stmt->fetch()['total'];
    
    // Llamadas por IAX
    $stmt = $db->query("SELECT COUNT(*) as total FROM calls WHERE via_iax = 1");
    $stats['llamadas_iax'] = $stmt->fetch()['total'];
    
    // ============================================
    // 5. OBTENER DATOS PARA TABLAS
    // ============================================
    
    // Nodos
    $stmt = $db->query("SELECT * FROM nodes ORDER BY 
        FIELD(status, 'online', 'idle', 'offline'),
        last_seen DESC");
    $nodos = $stmt->fetchAll();
    
    // Llamadas recientes
    $stmt = $db->query("SELECT * FROM calls ORDER BY start_time DESC LIMIT 15");
    $llamadas = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $dbError = $e->getMessage();
    error_log("ChileMon DB Error: " . $dbError);
}
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ChileMon Admin v0.3.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root { --chile-blue: #0039A6; --chile-red: #D52B1E; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f8f9fa; }
        .admin-header { background: linear-gradient(135deg, var(--chile-blue) 0%, #0052cc 100%); color: white; padding: 1.5rem 0; border-bottom: 4px solid var(--chile-red); }
        .stat-card { border-radius: 10px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .badge-online { background: #28a745; }
        .badge-offline { background: #dc3545; }
        .badge-idle { background: #ffc107; color: #212529; }
        .badge-iax { background: #6f42c1; }
        .node-id { font-family: 'Courier New', monospace; color: #0039A6; font-weight: bold; }
        .footer-info { background: #f8f9fa; border-top: 1px solid #dee2e6; color: #6c757d; }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <div style="width: 40px; height: 27px; background: linear-gradient(to bottom, #0039A6 33%, #FFFFFF 33%, #FFFFFF 66%, #D52B1E 66%); border-radius: 3px;"></div>
                        </div>
                        <div>
                            <h1 class="h3 mb-1">
                                <strong>ChileMon</strong> 
                                <small class="opacity-75">Admin Panel v0.3.0</small>
                            </h1>
                            <p class="mb-0 small opacity-75">
                                <i class="bi bi-database"></i> 
                                <?php if ($dbError): ?>
                                <span class="text-warning">⚠️ Error de conexión</span>
                                <?php else: ?>
                                <span class="text-success">✅ MySQL conectado</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <small class="opacity-75">
                        <i class="bi bi-person-fill"></i> <?= htmlspecialchars($user) ?>
                        | <a href="?logout=1" class="text-white text-decoration-none"><i class="bi bi-box-arrow-right"></i> Salir</a>
                    </small>
                </div>
            </div>
        </div>
    </header>

    <main class="container mt-4">
        <?php if ($dbError): ?>
        <div class="alert alert-danger">
            <h4><i class="bi bi-exclamation-triangle"></i> Error de conexión</h4>
            <p><?= htmlspecialchars($dbError) ?></p>
            <a href="fix-database.php" class="btn btn-warning btn-sm">Reparar base de datos</a>
        </div>
        <?php else: ?>
        
        <!-- Estadísticas -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h2 class="text-primary"><?= $stats['total_nodos'] ?></h2>
                        <p class="text-muted mb-0"><i class="bi bi-diagram-3"></i> Nodos</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h2 class="text-success"><?= $stats['nodos_online'] ?></h2>
                        <p class="text-muted mb-0"><i class="bi bi-check-circle"></i> En línea</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h2 class="text-info"><?= $stats['llamadas_hoy'] ?></h2>
                        <p class="text-muted mb-0"><i class="bi bi-telephone"></i> Llamadas hoy</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card">
                    <div class="card-body text-center">
                        <h2 style="color: #6f42c1;"><?= $stats['llamadas_iax'] ?></h2>
                        <p class="text-muted mb-0"><i class="bi bi-telephone-forward"></i> Via IAX</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Nodos -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Nodos (<?= count($nodos) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($nodos)): ?>
                <p class="text-muted text-center">No hay nodos</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Estado</th>
                                <th>Última vez</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nodos as $nodo): ?>
                            <tr>
                                <td><span class="node-id"><?= htmlspecialchars($nodo['node_id']) ?></span></td>
                                <td><?= htmlspecialchars($nodo['name']) ?></td>
                                <td>
                                    <span class="badge <?= $nodo['status'] == 'online' ? 'badge-online' : 'badge-offline' ?>">
                                        <?= $nodo['status'] ?>
                                    </span>
                                </td>
                                <td><?= date('H:i', strtotime($nodo['last_seen'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Llamadas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-telephone"></i> Llamadas recientes</h5>
            </div>
            <div class="card-body">
                <?php if (empty($llamadas)): ?>
                <p class="text-muted text-center">No hay llamadas</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Desde</th>
                                <th>Hacia</th>
                                <th>Hora</th>
                                <th>IAX</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($llamadas as $llamada): ?>
                            <tr>
                                <td><?= htmlspecialchars($llamada['call_from']) ?></td>
                                <td><?= htmlspecialchars($llamada['call_to']) ?></td>
                                <td><?= date('H:i', strtotime($llamada['start_time'])) ?></td>
                                <td>
                                    <?php if ($llamada['via_iax'] == 1): ?>
                                    <span class="badge badge-iax">Sí</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="footer-info mt-4 py-3">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <small><strong>ChileMon Admin v0.3.0</strong></small>
                </div>
                <div class="col-md-6 text-end">
                    <small>
                        <?= date('d/m/Y H:i') ?> | 
                        <a href="index.php" class="text-decoration-none">Dashboard</a>
                    </small>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>