<!DOCTYPE html>
<html lang="es-CL" data-bs-theme="<?= $darkMode ? 'dark' : 'light' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= APP_NAME ?> v<?= APP_VERSION ?> - Supermon Style</title>

  <!-- Bootstrap 5 + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">

  <?php
    $cssPath = dirname(__DIR__, 2) . '/assets/css/dashboard.css'; // public/assets/css/dashboard.css
    $cssVer  = file_exists($cssPath) ? (string)filemtime($cssPath) : (string)APP_VERSION;
  ?>
  
  <!-- CSS del Dashboard -->
  <link rel="stylesheet" href="<?= rtrim(BASE_URL, '/') ?>/assets/css/dashboard.css?v=<?= time() ?>">
</head>
<body>