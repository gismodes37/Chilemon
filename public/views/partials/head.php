<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es-CL" data-bs-theme="<?= $darkMode ? 'dark' : 'light' ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <?php \App\Auth\Auth::startSession(); ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(\App\Auth\Auth::csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
  
  <title><?= APP_NAME ?> v<?= APP_VERSION ?> - Supermon Style</title>

  <!-- Bootstrap 5 + Icons (SRI protected) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.min.css" integrity="sha384-mddk2pOtleiE3UNWQfF4qF2ldD2xQcEU6s4wFcyQw2LV6G2GsePwJUVa3XtVnjXK" crossorigin="anonymous">

  <?php
    $cssPath = dirname(__DIR__, 2) . '/assets/css/dashboard.css';
    $cssVer  = file_exists($cssPath) ? (string)filemtime($cssPath) : (string)APP_VERSION;
  ?>
  
  <!-- Leaflet (map picker en registro) -->
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous">

  <!-- CSS del Dashboard -->
  <link rel="stylesheet" href="<?= rtrim(BASE_URL, '/') ?>/assets/css/dashboard.css?v=<?= $cssVer ?>">
</head>
<body>