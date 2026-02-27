<?php
// Variables esperadas: $error, $oldUser, $_SESSION['csrf_token']
// Si usas controlador + vista, esto ya viene desde login.php

$csrfEsc = htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8');
$errorEsc = $error ? htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') : '';
$oldUserEsc = htmlspecialchars((string)$oldUser, ENT_QUOTES, 'UTF-8');

$serverLabel = defined('ASL_NODE') ? (string)ASL_NODE : 'nodo';
$serverLabelEsc = htmlspecialchars($serverLabel, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ChileMon - System Manager</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    :root{
      --cm-blue-1:#041255;
      --cm-blue-2:#121859;
      --cm-cyan-1:#01fefd;
      --cm-cyan-2:#36d7ff;
      --cm-cyan-3:#50dbe8;
    }
    body{
      min-height:100vh;
      margin:0;
      background:
        radial-gradient(circle at 20% 10%, rgba(54,215,255,.28), transparent 40%),
        radial-gradient(circle at 80% 30%, rgba(1,254,253,.18), transparent 45%),
        radial-gradient(circle at 50% 80%, rgba(4,18,85,.55), transparent 55%),
        linear-gradient(135deg, var(--cm-blue-1) 0%, var(--cm-blue-2) 55%, var(--cm-blue-1) 100%);
      color:#fff;
    }

/* Base: sigue dividido y centrado */
.split{
  min-height:100vh;
  display:flex;
}

.left,
.right{
  display:flex;
  align-items:center;
  padding: 40px;
}

/* En pantallas grandes: estilo ASL (login más al centro óptico) */
@media (min-width: 1200px){
  .left{
    flex: 0 0 42%;
    justify-content: flex-end;   /* empuja el card hacia el centro */
    padding-right: 72px;         /* separa del centro */
  }
  .right{
  flex: 0 0 45%;
  display:flex;
  align-items:center;
  justify-content:flex-end; /* <-- clave */
  padding-right:10%;
  padding-left:40px;
  position:relative;
}
}

/* En pantallas medianas: 50/50 centrado */
@media (min-width: 992px) and (max-width: 1199px){
  .left, .right{
    flex: 0 0 50%;
    justify-content:center;
  }
  .right{ position:relative; overflow:hidden; }
}

/* Mobile: apila como ya lo tienes */
@media (max-width: 992px){
  .split{ flex-direction: column; }
  .left{ flex: 0 0 auto; justify-content:center; }
  .right{ min-height: 46vh; justify-content:center; position:relative; overflow:hidden; }
  .bigTitle{ font-size: 44px; }
}

    .glass{
      width:100%;
      max-width:420px;
      background: rgba(255,255,255,.10);
      border: 1px solid rgba(255,255,255,.18);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      border-radius: 18px;
      box-shadow: 0 12px 38px rgba(0,0,0,.35);
    }

    .brand{
      font-weight:800;
      letter-spacing:.3px;
    }

    .muted{ opacity:.8; }

    .logoMark{
      width:56px;height:56px;
      border-radius: 50%;
      display:flex;
      align-items:center;
      justify-content:center;
      background: rgba(54,215,255,.18);
      border: 1px solid rgba(255,255,255,.22);
      font-size: 26px;
      line-height:1;
    }

    .btn-chilemon{
      background: linear-gradient(90deg, rgba(54,215,255,.95), rgba(1,254,253,.95));
      border:0;
      color:#07203b;
      font-weight:700;
    }
    .btn-chilemon:hover{ filter: brightness(1.05); }

    .right .bigLogo{
      text-align:center;
      max-width: 680px;
    }

    .bigLogo img{
      transform: scale(0.70);
    }
    .bigTitle{
      font-size: 110px;      /* antes era 64 */
      font-weight: 750;
      letter-spacing: 1px;
      line-height:1.05;
      margin: 0;
    }
    .pill{
      display:inline-block;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,.10);
      border: 1px solid rgba(255,255,255,.18);
      font-weight:600;
      margin-top: 12px;
    }
    .tagline{
      margin-top: 14px;
      font-size: 22px;
      font-style: bold;
      opacity: 0.7;
    }

    /* Detalle gráfico sutil */
    .orb{
      position:absolute;
      width:520px;height:520px;
      border-radius:50%;
      background: radial-gradient(circle, rgba(54,215,255,.25), transparent 60%);
      right:-160px; top:-120px;
      filter: blur(0px);
      pointer-events:none;
    }
    .orb2{
      position:absolute;
      width:560px;height:560px;
      border-radius:50%;
      background: radial-gradient(circle, rgba(1,254,253,.18), transparent 60%);
      left:-220px; bottom:-180px;
      pointer-events:none;
    }

    /* Responsive: en móvil apila */
    @media (max-width: 992px){
      .split{ flex-direction: column; }
      .left{ max-width: none; flex: 0 0 auto; }
      .right{ min-height: 46vh; }
      .bigTitle{ font-size: 44px; }
    }

.brand-bg{
  transform: scale(.70);
  transform-origin: center;
}

.overlay-text{
  position: absolute;
  inset: 0;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
}
  </style>
</head>

<body>
  <div class="split">
    <!-- IZQUIERDA: LOGIN -->
    <section class="left">
      <div class="glass p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="logoMark">🇨🇱</div>
          <div>
            <div class="brand fs-4">ChileMon</div>
            <div class="muted small">System Manager</div>
          </div>
        </div>

        <?php if ($errorEsc): ?>
          <div class="alert alert-danger py-2 mb-3"><?= $errorEsc ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
          <input type="hidden" name="csrf_token" value="<?= $csrfEsc ?>">

          <div class="mb-3">
            <label class="form-label">Nombre de usuario</label>
            <input class="form-control" name="username" required
                   autocomplete="username" autocapitalize="none" spellcheck="false"
                   value="<?= $oldUserEsc ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input class="form-control" type="password" name="password" required
                   autocomplete="current-password">
          </div>

          <button class="btn btn-chilemon w-100 py-2" type="submit">Iniciar sesión</button>
        </form>

        <div class="mt-3 small muted">
          Servidor: <?= $serverLabelEsc ?>
        </div>

        <div class="mt-3 text-white-50 small">
          © <?= date('Y') ?> ChileMon
        </div>
      </div>
    </section>

    <!-- DERECHA: BRANDING -->
    <section class="right">
  <div class="orb"></div>
  <div class="orb2"></div>

  <div class="bigLogo position-relative text-center">
    
    <img src="<?= BASE_PATH ?>/assets/img/chile-flag-brush.png"
         alt="Chile"
         class="brand-bg">

    <div class="overlay-text">
      <h1 class="bigTitle">ChileMon</h1>
      <div class="pill">Supermon Style</div>
      <div class="tagline">
        Dashboard para nodos <strong class="badge text-dark" style="background-color:#66A01B;">AllStarLink</strong> Ham Radio
      </div>
    </div>

  </div>
</section>
  </div>
</body>
</html>