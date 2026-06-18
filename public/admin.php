<?php
declare(strict_types=1);

/**
 * public/admin.php
 * -----------------------------------------------
 * Panel de administración de ChileMon.
 *
 * Seguridad:
 *   - Usa el sistema de autenticación ChileMon (Auth)
 *   - Solo accesible para usuarios con rol admin
 *   - SRI en todos los CDN
 *   - Consultas contra SQLite vía Database singleton
 * -----------------------------------------------
 */

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;
use App\Auth\UserRole;
use App\Core\Database;

// Autenticación
Auth::startSession();
Auth::requireLogin();
Auth::requireAdmin();

$username = Auth::getUsername() ?: 'Admin';

/**
 * Format bytes to a human-readable string.
 */
function formatBytes(int $bytes): string
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Recursively calculate the size of a directory in bytes.
 */
function recursiveDirSize(string $dir): int
{
    $size = 0;
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $size += $file->getSize();
    }
    return $size;
}

// --- User Management POST handlers ---
$action = (string) ($_POST['action'] ?? '');
$actionMsg = '';

if ($action !== '' && in_array($action, ['create', 'toggle-role', 'delete'], true)) {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!Auth::validateCsrf($csrfToken)) {
        $actionMsg = 'CSRF token inválido.';
    } else {
        try {
            $dbAction = Database::getConnection();

            if ($action === 'create') {
                $newUsername = trim((string) ($_POST['username'] ?? ''));
                $newPassword = (string) ($_POST['password'] ?? '');
                if ($newUsername === '' || $newPassword === '') {
                    $actionMsg = 'Usuario y contraseña son requeridos.';
                } else {
                    $stmt = $dbAction->prepare(
                        "INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)"
                    );
                    $stmt->execute([
                        ':username' => $newUsername,
                        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        ':role' => 'user',
                    ]);
                    header('Location: ' . rtrim(BASE_URL, '/') . '/admin.php');
                    exit;
                }
            } elseif ($action === 'toggle-role') {
                $targetId = (string) ($_POST['user_id'] ?? '');
                if (ctype_digit($targetId) && (int) $targetId !== Auth::getUserId()) {
                    $stmt = $dbAction->prepare("SELECT role FROM users WHERE id = :id");
                    $stmt->execute([':id' => (int) $targetId]);
                    $currentRole = $stmt->fetchColumn();
                    if ($currentRole !== false) {
                        $newRole = $currentRole === 'admin' ? 'user' : 'admin';
                        $stmt = $dbAction->prepare("UPDATE users SET role = :role WHERE id = :id");
                        $stmt->execute([':role' => $newRole, ':id' => (int) $targetId]);
                        header('Location: ' . rtrim(BASE_URL, '/') . '/admin.php');
                        exit;
                    }
                }
            } elseif ($action === 'delete') {
                $targetId = (string) ($_POST['user_id'] ?? '');
                if (ctype_digit($targetId) && (int) $targetId !== Auth::getUserId()) {
                    $stmt = $dbAction->prepare("DELETE FROM users WHERE id = :id");
                    $stmt->execute([':id' => (int) $targetId]);
                    header('Location: ' . rtrim(BASE_URL, '/') . '/admin.php');
                    exit;
                }
            }
        } catch (\Throwable $e) {
            $actionMsg = 'Error en acción: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}

// Recolectar estadísticas
$stats = [
    'total_nodos'      => 0,
    'nodos_online'     => 0,
    'nodos_offline'    => 0,
    'total_eventos'    => 0,
    'eventos_connect'  => 0,
    'eventos_disconnect' => 0,
    'total_usuarios'   => 0,
    'total_favoritos'  => 0,
    'total_api_attempts' => 0,
];

$nodos = [];
$eventos = [];
$users = [];
$sysSqliteVersion = 'N/A';
$sysDbPath = '';
$sysDbSize = 'N/A';
$sysDataSize = 'N/A';
$dbError = null;

try {
    $db = Database::getConnection();

    // Total nodos
    $stats['total_nodos'] = (int) $db->query("SELECT COUNT(*) FROM nodes")->fetchColumn();

    // Nodos por estado (aproximado: si existe en nodes, está/se ha visto)
    $stats['nodos_online'] = (int) $db->query("SELECT COUNT(*) FROM nodes")->fetchColumn();

    // Eventos
    $stats['total_eventos'] = (int) $db->query("SELECT COUNT(*) FROM node_events")->fetchColumn();
    $stats['eventos_connect'] = (int) $db->query("SELECT COUNT(*) FROM node_events WHERE event_type = 'connect'")->fetchColumn();
    $stats['eventos_disconnect'] = (int) $db->query("SELECT COUNT(*) FROM node_events WHERE event_type = 'disconnect'")->fetchColumn();

    // Usuarios
    $stats['total_usuarios'] = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

    // Favoritos
    $stats['total_favoritos'] = (int) $db->query("SELECT COUNT(*) FROM favorites")->fetchColumn();

    // System info
    $sysSqliteVersion = (string) $db->query("SELECT sqlite_version()")->fetchColumn();
    try {
        $row = $db->query("PRAGMA database_list")->fetch();
        $sysDbPath = (string) ($row['file'] ?? '');
        if ($sysDbPath !== '' && file_exists($sysDbPath)) {
            $size = @filesize($sysDbPath);
            $sysDbSize = $size !== false ? formatBytes($size) : 'N/A';
        }
    } catch (\Throwable $ignored) {}
    try {
        $sysDataSize = formatBytes(recursiveDirSize(DATA_PATH));
    } catch (\Throwable $ignored) {}

    // Users list for management
    $users = $db->query("SELECT id, username, role, created_at FROM users ORDER BY id ASC")->fetchAll();

    // API attempts (útlima hora)
    $cutoff = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $db->prepare("SELECT COUNT(*) FROM api_attempts WHERE created_at >= :cutoff");
    $stmt->execute([':cutoff' => $cutoff]);
    $stats['total_api_attempts'] = (int) $stmt->fetchColumn();

    // Lista de nodos
    $nodos = $db->query("SELECT node_id, users, last_seen FROM nodes ORDER BY last_seen DESC LIMIT 50")->fetchAll();

    // Eventos recientes
    $eventos = $db->query("SELECT id, node_id, event_type, username, created_at FROM node_events ORDER BY id DESC LIMIT 20")->fetchAll();

} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    error_log("[admin.php] DB Error: " . $dbError);
}
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ChileMon Admin v<?= defined('APP_VERSION') ? APP_VERSION : '0.4.0' ?></title>

  <!-- Bootstrap 5 + Icons (SRI protected) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.min.css" integrity="sha384-mddk2pOtleiE3UNWQfF4qF2ldD2xQcEU6s4wFcyQw2LV6G2GsePwJUVa3XtVnjXK" crossorigin="anonymous">

  <style>
    :root { --cm-blue: #0039A6; --cm-red: #D52B1E; }
    body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f8f9fa; }
    .admin-header { background: linear-gradient(135deg, var(--cm-blue) 0%, #0052cc 100%); color: white; padding: 1.25rem 0; border-bottom: 4px solid var(--cm-red); }
    .stat-card { border-radius: 10px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-3px); }
    .node-id { font-family: 'Courier New', monospace; color: var(--cm-blue); font-weight: bold; }
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
            <h1 class="h4 mb-1"><strong>ChileMon</strong> <small class="opacity-75">Admin Panel</small></h1>
            <p class="mb-0 small opacity-75">
              <i class="bi bi-database"></i>
              <?= $dbError ? '<span class="text-warning">⚠️ ' . htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') . '</span>' : '<span class="text-success">✅ SQLite conectado</span>' ?>
            </p>
          </div>
        </div>
      </div>
      <div class="col-md-4 text-end">
        <small class="opacity-75">
          <i class="bi bi-person-fill"></i> <?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>
          <span class="badge bg-light text-dark ms-1">admin</span>
          | <a href="<?= rtrim(BASE_URL, '/') ?>/index.php" class="text-white text-decoration-none"><i class="bi bi-speedometer2"></i> Dashboard</a>
          | <a href="<?= rtrim(BASE_URL, '/') ?>/logout.php" class="text-white text-decoration-none"><i class="bi bi-box-arrow-right"></i> Salir</a>
        </small>
      </div>
    </div>
  </div>
</header>

<main class="container mt-4">

  <?php if ($dbError): ?>
    <div class="alert alert-danger">
      <h5><i class="bi bi-exclamation-triangle"></i> Error de base de datos</h5>
      <p class="mb-0"><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  <?php else: ?>

  <!-- Stats Cards -->
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
          <h2 class="text-info"><?= $stats['total_eventos'] ?></h2>
          <p class="text-muted mb-0"><i class="bi bi-activity"></i> Eventos</p>
        </div>
      </div>
    </div>
    <div class="col-md-3 mb-3">
      <div class="card stat-card">
        <div class="card-body text-center">
          <h2 class="text-success"><?= $stats['total_usuarios'] ?></h2>
          <p class="text-muted mb-0"><i class="bi bi-people"></i> Usuarios</p>
        </div>
      </div>
    </div>
    <div class="col-md-3 mb-3">
      <div class="card stat-card">
        <div class="card-body text-center">
          <h2 style="color: #e83e8c;"><?= $stats['total_favoritos'] ?></h2>
          <p class="text-muted mb-0"><i class="bi bi-star"></i> Favoritos</p>
        </div>
      </div>
    </div>
  </div>

  <div class="row mb-4">
    <div class="col-md-6 mb-3">
      <div class="card stat-card">
        <div class="card-body text-center">
          <h2 class="text-success"><?= $stats['eventos_connect'] ?></h2>
          <p class="text-muted mb-0"><i class="bi bi-box-arrow-in-right"></i> Conexiones</p>
        </div>
      </div>
    </div>
    <div class="col-md-6 mb-3">
      <div class="card stat-card">
        <div class="card-body text-center">
          <h2 class="text-danger"><?= $stats['eventos_disconnect'] ?></h2>
          <p class="text-muted mb-0"><i class="bi bi-box-arrow-right"></i> Desconexiones</p>
        </div>
      </div>
    </div>
  </div>

  <!-- System Info -->
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0"><i class="bi bi-info-circle"></i> Información del Sistema</h5>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-layers fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">PHP Version</small><span class="fw-medium"><?= PHP_VERSION ?></span></div>
          </div>
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-server fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Servidor</small><span class="fw-medium"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-database fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">SQLite Version</small><span class="fw-medium"><?= htmlspecialchars($sysSqliteVersion ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-pc-display fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Sistema Operativo</small><span class="fw-medium"><?= PHP_OS_FAMILY ?></span></div>
          </div>
          <div class="d-flex align-items-center">
            <i class="bi bi-globe2 fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Hostname</small><span class="fw-medium"><?= htmlspecialchars(gethostname(), ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-folder fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Ruta DB</small><span class="fw-medium small"><?= htmlspecialchars($sysDbPath ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-file-earmark fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Tamaño DB</small><span class="fw-medium"><?= htmlspecialchars($sysDbSize, ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
          <div class="d-flex align-items-center mb-3">
            <i class="bi bi-hdd-stack fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Tamaño Data</small><span class="fw-medium"><?= htmlspecialchars($sysDataSize, ENT_QUOTES, 'UTF-8') ?></span></div>
          </div>
          <div class="d-flex align-items-center">
            <i class="bi bi-clock fs-4 text-primary me-3" style="width: 24px;"></i>
            <div><small class="text-muted d-block">Hora Servidor</small><span class="fw-medium"><?= date('Y-m-d H:i:s') ?> <small class="text-muted"><?= htmlspecialchars(date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?></small></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Nodos -->
  <?php if (!empty($nodos)): ?>
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Nodos registrados (<?= count($nodos) ?>)</h5>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>Node ID</th>
              <th>Usuarios</th>
              <th>Última vez</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($nodos as $n): ?>
            <tr>
              <td><span class="node-id"><?= htmlspecialchars((string)($n['node_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
              <td><?= (int)($n['users'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string)($n['last_seen'] ?? '--'), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Eventos recientes -->
  <?php if (!empty($eventos)): ?>
  <div class="card">
    <div class="card-header">
      <h5 class="mb-0"><i class="bi bi-activity"></i> Eventos recientes (<?= count($eventos) ?>)</h5>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nodo</th>
              <th>Tipo</th>
              <th>Usuario</th>
              <th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($eventos as $e): ?>
            <tr>
              <td><?= (int)($e['id'] ?? 0) ?></td>
              <td><span class="node-id"><?= htmlspecialchars((string)($e['node_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
              <td>
                <?php $tipo = $e['event_type'] ?? ''; ?>
                <?php if ($tipo === 'connect'): ?>
                  <span class="badge bg-success">connect</span>
                <?php elseif ($tipo === 'disconnect'): ?>
                  <span class="badge bg-danger">disconnect</span>
                <?php else: ?>
                  <span class="badge bg-secondary"><?= htmlspecialchars((string)$tipo, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars((string)($e['username'] ?? '--'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)($e['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <!-- User Management -->
  <?php if ($dbError === null): ?>
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0"><i class="bi bi-people-fill"></i> Gestión de Usuarios</h5>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>ID</th>
              <th>Usuario</th>
              <th>Rol</th>
              <th>Creado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($users)): ?>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><?= (int) ($u['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($u['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php $role = (string) ($u['role'] ?? 'user'); ?>
                  <?php if ($role === 'admin'): ?>
                    <span class="badge bg-danger">admin</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">user</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) ($u['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                  <?php if ((int) ($u['id'] ?? 0) !== Auth::getUserId()): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="user_id" value="<?= (int) ($u['id'] ?? 0) ?>">
                    <input type="hidden" name="action" value="toggle-role">
                    <button type="submit" class="btn btn-sm <?= $role === 'admin' ? 'btn-warning' : 'btn-success' ?>">
                      <?= $role === 'admin' ? 'Demote' : 'Promote' ?>
                    </button>
                  </form>
                  <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar usuario «<?= htmlspecialchars((string) ($u['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>»?')">
                    <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
                    <input type="hidden" name="user_id" value="<?= (int) ($u['id'] ?? 0) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                  </form>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5" class="text-muted text-center py-3">No hay usuarios registrados.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Create User -->
  <div class="card mb-4">
    <div class="card-header">
      <h5 class="mb-0"><i class="bi bi-person-plus"></i> Crear Usuario</h5>
    </div>
    <div class="card-body">
      <?php if ($actionMsg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8') ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <form method="post" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= Auth::csrfToken() ?>">
        <input type="hidden" name="action" value="create">
        <div class="col-md-5">
          <label for="username" class="form-label">Usuario</label>
          <input type="text" class="form-control" id="username" name="username" required autocomplete="off">
        </div>
        <div class="col-md-5">
          <label for="password" class="form-label">Contraseña</label>
          <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-primary w-100">Crear</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <footer class="mt-4 py-3 text-center text-muted small">
    <p class="mb-0">
      ChileMon v<?= defined('APP_VERSION') ? APP_VERSION : '0.4.0' ?> &mdash;
      PHP <?= PHP_VERSION ?> &mdash;
      SQLite
    </p>
  </footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
</body>
</html>
