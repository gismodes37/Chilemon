<?php
// header.php (public/views/partials)

// --- Bandera ---
$flagPath = dirname(__DIR__, 2) . '/assets/img/Flag_of_chile.svg';
$flagUrl  = file_exists($flagPath)
  ? rtrim(BASE_URL, '/') . '/assets/img/Flag_of_chile.svg'
  : 'https://flagcdn.com/w40/cl.png';

// --- Imagen de cabecera personalizable ---
$headerImgPath = dirname(__DIR__, 2) . '/assets/img/header.jpg';
$headerImgUrl  = file_exists($headerImgPath)
  ? rtrim(BASE_URL, '/') . '/assets/img/header.jpg'
  : '';

// --- Puerto / protocolo ---
$port = (int)($systemInfo['web_port'] ?? 80);
$protoLabel = ($port === 80) ? 'HTTP' : (($port === 443) ? 'HTTPS' : "Port $port");

// --- Tagline personalizable (debajo del logo/título) ---
// Opción 1: constante HEADER_TAGLINE (recomendado)
// Opción 2: si no existe, queda vacío
$headerTagline = defined('HEADER_TAGLINE') ? (string)HEADER_TAGLINE : '';
$headerTagline = trim($headerTagline);
$headerTaglineEsc = htmlspecialchars($headerTagline, ENT_QUOTES, 'UTF-8');

// Helpers
$headerClass = 'supermon-header py-3' . ($headerImgUrl ? ' has-header-bg' : '');
$headerStyle = $headerImgUrl
  ? '--header-bg:url(\'' . htmlspecialchars($headerImgUrl, ENT_QUOTES, 'UTF-8') . '\')'
  : '';
?>

<header
  id="main-header"
  class="<?= $headerClass ?>"
  <?= $headerStyle ? 'style="' . $headerStyle . '"' : '' ?>
>
  <div class="container">
    <div class="row align-items-center">

      <!-- IZQUIERDA -->
      <div class="col-md-6">
        <h1 class="h4 mb-1 header-title d-flex align-items-center flex-wrap gap-2">

          <img src="<?= $flagUrl ?>" alt="Bandera de Chile"
               width="40" height="27"
               class="align-middle"
               style="border-radius:3px;border:1px solid #ddd;">

          <strong><?= APP_NAME ?></strong>
          <small class="opacity-75">v<?= APP_VERSION ?></small>
          <span class="header-badge ms-2">Supermon Style</span>
        </h1>

        <p class="mb-1 opacity-40">
          <i class="bi bi-wifi"></i> Dashboard para nodos
          <span class="badge text-dark" style="background-color:#66A01B;">AllStar Link</span>
          Chile
        </p> 

        <?php if ($headerTaglineEsc !== ''): ?>
          <div class="header-tagline mb-1">
            <?= $headerTaglineEsc ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- DERECHA -->
      <div class="col-md-6 text-md-end">
        <div class="d-flex justify-content-md-end align-items-center gap-3 flex-wrap">

          <div class="text-end">
            <div class="mb-1">

              <button class="btn btn-sm btn-outline-warning"
                      data-bs-toggle="modal"
                      data-bs-target="#favoritesModal">
                <i class="bi bi-star"></i> Favoritos
              </button>

              <span class="badge bg-success">
                <i class="bi bi-check-circle"></i>
                <?= (int)($estadisticas['nodos_online'] ?? 0) ?> Online
              </span>

              <span class="badge bg-warning ms-1">
                <i class="bi bi-clock"></i>
                <?= (int)($estadisticas['nodos_idle'] ?? 0) ?> Idle
              </span>

            </div>

            <div class="quick-info d-none d-md-block">

              <span class="quick-info-item">
                <i class="bi bi-globe"></i>
                <?= htmlspecialchars($protoLabel, ENT_QUOTES, 'UTF-8') ?>
              </span>

              <span class="quick-info-item">
                <i class="bi bi-thermometer-half"></i>
                <?php $t = (float)($systemInfo['cpu_temp_c'] ?? 0); ?>
                <span class="<?= $t < 50 ? 'temp-low' : ($t < 70 ? 'temp-medium' : 'temp-high') ?>">
                  <?= $t ?>°C
                </span>
              </span>

              <span class="quick-info-item">
                <i class="bi bi-database"></i>
                SQLite | <span id="current-time"><?= date('H:i') ?></span>
              </span>

            </div>
          </div>

          <div class="d-flex flex-column align-items-end">
            <small class="opacity-75 mb-1">
              <?= htmlspecialchars((string)($username ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </small>

            <form method="post"
                  action="<?= rtrim(BASE_PATH, '/') ?>/logout.php"
                  class="d-inline">

              <input type="hidden"
                     name="csrf_token"
                     value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

              <button type="submit" class="btn btn-outline-danger btn-sm">
                Salir
              </button>
            </form>
          </div>

        </div>
      </div>

    </div>
  </div>
</header>