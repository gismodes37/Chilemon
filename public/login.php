<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

Auth::startSession();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');

    if ($u === '' || $p === '') {
        $error = 'Completa usuario y contraseña.';
    } else {
        if (Auth::attemptLogin($u, $p)) {
            header('Location: ' . rtrim(BASE_PATH, '/') . '/index.php');
            exit;
        }
        $error = Auth::getLastError() ?? 'Usuario o contraseña incorrectos.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ChileMon - Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-5">
        <div class="card shadow-sm">
          <div class="card-header bg-dark text-white">
            <strong>ChileMon</strong> <span class="opacity-75">Login</span>
          </div>
          <div class="card-body">
            <?php if ($error): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
              <div class="mb-3">
                <label class="form-label">Usuario</label>
                <input class="form-control" name="username" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Contraseña</label>
                <input class="form-control" type="password" name="password" required>
              </div>
              <button class="btn btn-success w-100">Ingresar</button>
            </form>

            <hr class="my-4">
            <small class="text-muted">
              Si no tienes usuario, ejecuta el instalador por consola en ASL3.
            </small>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
