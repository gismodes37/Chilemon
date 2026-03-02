<?php
// Archivo: public/views/auth/login.view.php  (o donde tengas esta vista)
// Variables esperadas: $error, $oldUser, $_SESSION['csrf_token']

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

    /* Layout split */
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

    /* Desktop grande: login hacia centro óptico */
    @media (min-width: 1200px){
      .left{
        flex: 0 0 42%;
        justify-content: flex-end;
        padding-right: 72px;
      }
      .right{
        flex: 0 0 58%;
        justify-content:center;
        position:relative;
        overflow:hidden;
      }
    }

    /* Desktop/Tablet */
    @media (min-width: 992px) and (max-width: 1199px){
      .left, .right{
        flex: 0 0 50%;
        justify-content:center;
      }
      .right{ position:relative; overflow:hidden; }
    }

    /* Mobile */
    @media (max-width: 992px){
      .split{ flex-direction: column; }
      .left{ justify-content:center; padding: 28px 18px; }
      .right{ min-height: 44vh; justify-content:center; padding: 18px; position:relative; overflow:hidden; }
    }

    /* Card glass */
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

    .brand{ font-weight:800; letter-spacing:.3px; }
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

    /* Orbs */
    .orb{
      position:absolute;
      width:520px;height:520px;
      border-radius:50%;
      background: radial-gradient(circle, rgba(54,215,255,.25), transparent 60%);
      right:-160px; top:-120px;
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

    /* Branding (derecha) */
    .brandStage{
      position:relative;
      width: min(760px, 100%);   /* un poco más ancho */
      display:flex;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding: 12px;
    }

    .brand-bg{
      width: min(520px, 90vw);
      height: auto;
      opacity: .95;
      filter: drop-shadow(0 12px 26px rgba(0,0,0,.25));
    }

    .overlay-text{
      position:absolute;
      inset:0;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:flex-end;   /* baja el contenido */
      padding-bottom: 10%;        /* controla cuánto baja */
      gap: 10px;
      text-align:center;
      width: 115%;           /* sobresale un poco */
      left: -7.5%;           /* lo centra visualmente */
    }

    .bigTitle{
      font-size: clamp(42px, 6vw, 96px);
      font-weight: 800;
      letter-spacing: 3.10px;
      line-height: 1.05;
      margin: 0;
      text-shadow: 0 10px 30px rgba(0,0,0,.35);
    }

    .tagline{
      font-size: clamp(14px, 1.6vw, 20px);
      opacity: .95;
      margin: 0;
      max-width: 46ch;
    }

    .asl-badge{
      font-size: clamp(14px, 1.6vw, 20px);
      max-width: 60ch;    /* antes estaba más corto */
      display:inline-block;
      padding: .15rem .5rem;
      border-radius: .65rem;
      font-weight: 700;
      background-color:#66A01B;
      color:#111;
      vertical-align: middle;
    }

    /* En mobile baja un poco el peso visual */
    @media (max-width: 992px){
      .brandStage{ width: min(520px, 100%); }
      .brand-bg{ width: min(420px, 92vw); }
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
          © <?= date('Y') ?> ChileMon - CA2IIG
        </div>
      </div>
    </section>

    <!-- DERECHA: BRANDING -->
    <section class="right">
      <div class="orb"></div>
      <div class="orb2"></div>

      <div class="brandStage">
        <img src="<?= BASE_PATH ?>/assets/img/chile-flag-brush.png" alt="Chile" class="brand-bg">

        <div class="overlay-text">
          <h1 class="bigTitle">ChileMon</h1>

          <p class="tagline">
            Dashboard para nodos
            <span class="asl-badge">AllStarLink</span>
            Ham Radio
          </p>
        </div>
      </div>
    </section>
  </div>
</body>
</html>