<?php
// header.php (public/views/partials)
$flagPath = dirname(__DIR__, 2) . '/assets/img/Flag_of_chile.svg'; // public/assets/img/...
$flagUrl  = file_exists($flagPath)
  ? rtrim(BASE_URL, '/') . '/assets/img/Flag_of_chile.svg'
  : 'https://flagcdn.com/w40/cl.png';

$port = (int)($systemInfo['web_port'] ?? 80);
$protoLabel = ($port === 80) ? 'HTTP' : (($port === 443) ? 'HTTPS' : "Port $port");
?>


<header class="supermon-header py-3" id="main-header">
  <div class="container">
    <div class="row align-items-center">

      <!-- IZQUIERDA: bandera + nombre -->
      <div class="col-md-6">
        <h1 class="h4 mb-1 header-title">
          <!-- bandera -->
          
          <img src="<?= $flagUrl; ?>" alt="Bandera de Chile" width="40" height="27" class="me-2 align-middle" style="border-radius:3px;border:1px solid #ddd;">
          
          <strong><?= APP_NAME ?></strong>
          <small class="opacity-75">v<?= APP_VERSION ?></small>
          <span class="header-badge ms-2">Supermon Style</span>
        </h1>

        <p class="mb-1 opacity-75">
          <i class="bi bi-wifi"></i> Dashboard para nodos
          <span class="badge text-dark" style="background-color:#66A01B;">AllStar Link</span>
          Chile
        </p>
      </div>

      <!-- DERECHA: badges + quick-info + usuario/logout -->
      <div class="col-md-6 text-md-end">
        <div class="d-flex justify-content-md-end align-items-center gap-3">

          <div class="text-end">
            <div class="mb-1">
              <span class="badge bg-success">
                <i class="bi bi-check-circle"></i> <?= (int)$estadisticas['nodos_online'] ?> Online
              </span>
              <span class="badge bg-warning ms-1">
                <i class="bi bi-clock"></i> <?= (int)$estadisticas['nodos_idle'] ?> Idle
              </span>
            </div>

            <div class="quick-info d-none d-md-block">
              <span class="quick-info-item">
                <i class="bi bi-globe"></i>
                <?php
                  $port = (int)($systemInfo['web_port'] ?? 80);
                  echo $port === 80 ? 'HTTP' : ($port === 443 ? 'HTTPS' : "Port $port");
                ?>
              </span>

              <span class="quick-info-item">
                <i class="bi bi-thermometer-half"></i>
                <?php $t = (float)($systemInfo['cpu_temp_c'] ?? 0); ?>
                <span class="<?= $t < 50 ? 'temp-low' : ($t < 70 ? 'temp-medium' : 'temp-high') ?>">
                  <?= $t ?>°C
                </span>
              </span>

              <span class="quick-info-item">
                <i class="bi bi-database"></i> SQLite | <span id="current-time"><?= date('H:i') ?></span>
              </span>
            </div>
          </div>

          <div class="d-flex flex-column align-items-end">
            <small class="opacity-75 mb-1"><?= htmlspecialchars($username ?? '') ?></small>

            <form method="post" action="<?= rtrim(BASE_PATH, '/') ?>/logout.php" class="d-inline">
                <input type="hidden" name="csrf_token"
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