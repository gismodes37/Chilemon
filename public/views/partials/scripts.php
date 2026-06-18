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
</body>
</html>