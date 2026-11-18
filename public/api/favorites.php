<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

Auth::startSession();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'Unauthorized']);
  exit;
}

$userId = (int)$_SESSION['user_id'];
$dbFile = DATA_PATH . '/chilemon.sqlite';

try {
  $db = new PDO('sqlite:' . $dbFile);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->exec("PRAGMA foreign_keys = ON;");

  // 1) Crear tabla base si no existe
  $db->exec("
    CREATE TABLE IF NOT EXISTS favorites (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      node_id TEXT NOT NULL
    );
  ");
  $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_favorites_user_node ON favorites(user_id, node_id);");

  // 2) Migración: asegurar columnas faltantes (para evitar: no such column: alias / created_at)
  $cols = [];
  $q = $db->query("PRAGMA table_info(favorites)");
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[(string)$row['name']] = true;
  }

  $ensureCol = function(string $name, string $ddl) use ($db, &$cols) {
    if (!isset($cols[$name])) {
      $db->exec("ALTER TABLE favorites ADD COLUMN {$ddl}");
      $cols[$name] = true;
    }
  };

  $ensureCol('alias',       "alias TEXT DEFAULT ''");
  $ensureCol('description', "description TEXT DEFAULT ''");
  $ensureCol('created_at',  "created_at TEXT");
  $ensureCol('updated_at',  "updated_at TEXT");

  // Rellenar fechas si están NULL (para tablas antiguas)
  $db->exec("UPDATE favorites SET created_at = COALESCE(created_at, datetime('now'))");
  $db->exec("UPDATE favorites SET updated_at = COALESCE(updated_at, datetime('now'))");

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  if ($method === 'GET') {
    $st = $db->prepare("
      SELECT id, node_id, alias, description, created_at, updated_at
      FROM favorites
      WHERE user_id = :uid
      ORDER BY updated_at DESC, id DESC
      LIMIT 200
    ");
    $st->execute([':uid' => $userId]);
    echo json_encode(['success' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
  }

  // POST — CSRF obligatorio (timing-safe, rota el token tras validar)
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!Auth::validateCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF inválido']);
    exit;
  }

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'upsert') {
    $node  = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($_POST['node_id'] ?? ''));
    $alias = trim((string)($_POST['alias'] ?? ''));
    $desc  = trim((string)($_POST['description'] ?? ''));

    if ($node === '') {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'node_id requerido']);
      exit;
    }

    // Insert si no existe
    $ins = $db->prepare("
      INSERT OR IGNORE INTO favorites(user_id, node_id, alias, description, created_at, updated_at)
      VALUES (:uid, :node, :alias, :desc, datetime('now'), datetime('now'))
    ");
    $ins->execute([':uid'=>$userId, ':node'=>$node, ':alias'=>$alias, ':desc'=>$desc]);

    // Update siempre (para editar)
    $up = $db->prepare("
      UPDATE favorites
      SET alias = :alias,
          description = :desc,
          updated_at = datetime('now')
      WHERE user_id = :uid AND node_id = :node
    ");
    $up->execute([':uid'=>$userId, ':node'=>$node, ':alias'=>$alias, ':desc'=>$desc]);

    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'delete') {
    $node = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($_POST['node_id'] ?? ''));
    if ($node === '') {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'node_id requerido']);
      exit;
    }

    $st = $db->prepare("DELETE FROM favorites WHERE user_id = :uid AND node_id = :node");
    $st->execute([':uid'=>$userId, ':node'=>$node]);

    echo json_encode(['success' => true]);
    exit;
  }

  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Acción inválida']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}