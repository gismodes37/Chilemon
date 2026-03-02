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

  // Schema safe
  $db->exec("
    CREATE TABLE IF NOT EXISTS favorites (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      node_id TEXT NOT NULL,
      alias TEXT DEFAULT '',
      description TEXT DEFAULT '',
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
  ");
  $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_favorites_user_node ON favorites(user_id, node_id);");

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

  // POST => valida CSRF
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!Auth::validateCsrf($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Bad Request (CSRF)']);
    exit;
  }

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'upsert') {
    $node = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($_POST['node_id'] ?? ''));
    $alias = trim((string)($_POST['alias'] ?? ''));
    $desc  = trim((string)($_POST['description'] ?? ''));

    if ($node === '') {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'node_id requerido']);
      exit;
    }

    // Insert or update (compat SQLite)
    $ins = $db->prepare("
      INSERT OR IGNORE INTO favorites(user_id, node_id, alias, description)
      VALUES (:uid, :node, :alias, :desc)
    ");
    $ins->execute([':uid'=>$userId, ':node'=>$node, ':alias'=>$alias, ':desc'=>$desc]);

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