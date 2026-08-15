<?php

declare(strict_types=1);

// scripts.php
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>

<script>
  window.CHILEMON_BASE = "<?= rtrim(BASE_URL, '/') ?>/";
  window.CHILEMON_PATH = "<?= rtrim(BASE_PATH, '/') ?>/";
</script>

<?php
$jsPath = dirname(__DIR__, 2) . '/assets/js/dashboard.js';
$jsVer  = file_exists($jsPath) ? (string)filemtime($jsPath) : (string)APP_VERSION;
?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/dashboard.js?v=<?= time() ?>"></script>

<?php
$vizJsPath = dirname(__DIR__, 2) . '/assets/js/audio-visualizer.js';
$vizJsVer  = file_exists($vizJsPath) ? (string)filemtime($vizJsPath) : (string)APP_VERSION;
?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/audio-visualizer.js?v=<?= $vizJsVer ?>"></script>

<?php
// Cache-bust con time() mientras estamos en desarrollo activo
?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ptt-widget.js?v=<?= time() ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof PTTWidget !== 'undefined') {
        window.pttWidget = new PTTWidget();
        window.pttWidget.init();
    }
});
</script>
</body>
</html>