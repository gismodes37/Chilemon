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
    // dev|prod (default prod)
    define('APP_ENV', getenv('CHILEMON_ENV') ?: 'prod');
}
if (!defined('ASL_NODE')) {
    define('ASL_NODE', getenv('CHILEMON_NODE') ?: '61916');
}

$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

/**
 * Permite override explícito por entorno (útil para dev/local y tests):
 * - CHILEMON_BASE_PATH=/chilemon   (o "/" o "")
 * - CHILEMON_BASE_URL=http://localhost/chilemon
 */
$envBasePath = getenv('CHILEMON_BASE_PATH') ?: '';
$envBaseUrl  = getenv('CHILEMON_BASE_URL') ?: '';

/**
 * BASE_PATH dinámico:
 * - Si hay reverse proxy, respeta X-Forwarded-Prefix
 * - Si no, usa el directorio del SCRIPT_NAME (/chilemon, /, etc)
 * Resultado: "/" o "/chilemon" (sin slash final)
 */
if (!defined('BASE_PATH')) {

    if ($envBasePath !== '') {
        $basePath = str_replace('\\', '/', trim($envBasePath));
    } elseif ($isCli) {
        // En CLI no hay SCRIPT_NAME. Deja "/" por defecto.
        $basePath = '/';
    } else {
        $forwardedPrefix = (string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '');
        $scriptName      = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
        $scriptDir       = str_replace('\\', '/', dirname($scriptName));

        $basePath = $forwardedPrefix !== '' ? $forwardedPrefix : $scriptDir;
    }

    // Normalizar
    $basePath = trim($basePath);
    if ($basePath === '' || $basePath === '.') {
        $basePath = '/';
    }
    if ($basePath !== '/' && $basePath[0] !== '/') {
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

    if ($envBaseUrl !== '') {
        $baseUrl = rtrim(trim($envBaseUrl), '/');
        // Si BASE_PATH es "/", no duplicar.
        $suffix = (BASE_PATH === '/' ? '' : BASE_PATH);
        define('BASE_URL', $baseUrl . $suffix);
    } elseif ($isCli) {
        // En CLI dejamos un placeholder razonable (no se usa para redirects HTTP).
        define('BASE_URL', 'http://localhost' . (BASE_PATH === '/' ? '' : BASE_PATH));
    } else {
        $isHttps =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);

        $protocol = $isHttps ? 'https' : 'http';

        // Host correcto detrás de proxy (si aplica)
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        // Si viene con múltiples hosts (proxy), tomar el primero
        if (strpos($host, ',') !== false) {
            $host = trim(explode(',', $host)[0]);
        }

        define('BASE_URL', $protocol . '://' . $host . (BASE_PATH === '/' ? '' : BASE_PATH));
    }
}

/**
 * Defaults básicos para sesiones (Auth::startSession termina de endurecer)
 * - No forzamos cookie_secure aquí porque depende de HTTPS real/proxy.
 */
/*
if (!$isCli) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
}*/

/**
 * Errores por entorno
 */
if (APP_ENV === 'dev') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}