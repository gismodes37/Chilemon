<?php
declare(strict_types=1);

/**
 * Bootstrap base ChileMon (single source of truth)
 * Define constantes UNA sola vez y calcula BASE_PATH dinámico.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', ROOT_PATH . '/public');
}
if (!defined('DATA_PATH')) {
    define('DATA_PATH', ROOT_PATH . '/data');
}
if (!defined('LOG_PATH')) {
    define('LOG_PATH', ROOT_PATH . '/logs');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'ChileMon');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('CHILEMON_ENV') ?: 'prod');
}
if (!defined('ASL_NODE')) {
    define('ASL_NODE', getenv('CHILEMON_NODE') ?: '61916');
}

/**
 * BASE_PATH dinámico:
 * - Si hay reverse proxy, respeta X-Forwarded-Prefix
 * - Si no, usa el directorio del SCRIPT_NAME (/chilemon, /, etc)
 * Resultado: "/" o "/chilemon" (sin slash final)
 */
if (!defined('BASE_PATH')) {
    $forwardedPrefix = $_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    $scriptDir = str_replace('\\', '/', dirname($scriptName));

    $basePath = $forwardedPrefix ?: $scriptDir;

    // Normalizar
    if ($basePath === '.' || $basePath === '') {
        $basePath = '/';
    }
    if ($basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
    // Quitar slash final excepto si es "/"
    if ($basePath !== '/') {
        $basePath = rtrim($basePath, '/');
    }

    define('BASE_PATH', $basePath);
}

/**
 * BASE_URL opcional, útil para armar links absolutos.
 */
if (!defined('BASE_URL')) {
    $isHttps =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $protocol = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    define('BASE_URL', $protocol . '://' . $host . (BASE_PATH === '/' ? '' : BASE_PATH));
}