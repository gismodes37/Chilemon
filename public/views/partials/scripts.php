<?php

declare(strict_types=1);

// scripts.php
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>

<script>
  window.CHILEMON_BASE = "<?= rtrim(BASE_URL, '/') ?>/";
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
$pttJsPath = dirname(__DIR__, 2) . '/assets/js/ptt-widget.js';
$pttJsVer  = file_exists($pttJsPath) ? (string)filemtime($pttJsPath) : (string)APP_VERSION;
?>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/ptt-widget.js?v=<?= $pttJsVer ?>"></script>

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