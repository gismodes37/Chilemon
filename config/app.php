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

/**
 * Carga configuración local del nodo si existe.
 * Este archivo NO debe versionarse.
 */
$localConfig = [];
$localConfigFile = ROOT_PATH . '/config/local.php';

if (file_exists($localConfigFile)) {
    $loadedLocalConfig = require $localConfigFile;
    if (is_array($loadedLocalConfig)) {
        $localConfig = $loadedLocalConfig;
    }
}

if (!defined('HEADER_TAGLINE')) {
    // Texto personalizable debajo del logo/título.
    // Prioridad:
    // 1. Variable de entorno
    // 2. config/local.php
    // 3. Texto por defecto actual
    define(
        'HEADER_TAGLINE',
        getenv('CHILEMON_HEADER_TAGLINE')
            ?: ($localConfig['header_tagline'] ?? 'Nodo: 494780 de La Serena')
    );
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'ChileMon');
}
if (!defined('APP_ENV')) {
    // dev|prod (default prod)
    define('APP_ENV', getenv('CHILEMON_ENV') ?: 'prod');
}

/**
 * Forzar config/local.php en producción.
 */
if (APP_ENV !== 'dev' && !file_exists($localConfigFile)) {
    throw new RuntimeException(
        'config/local.php no encontrado. Copia config/local.php.example a config/local.php y configura tus credenciales.'
    );
}

if (!defined('ASL_NODE')) {
    /**
     * Prioridad:
     * 1. Variable de entorno CHILEMON_NODE
     * 2. config/local.php => local_node
     * 3. Fallback temporal de compatibilidad
     *
     * Nota:
     * El valor 'YOUR_NODE' debería eliminarse en la fase final,
     * cuando el instalador genere siempre config/local.php.
     */
    define(
        'ASL_NODE',
        (string) (
            getenv('CHILEMON_NODE')
                ?: ($localConfig['local_node'] ?? '')
        )
    );
}

if (ASL_NODE === '') {
    throw new RuntimeException(
        'ASL_NODE no está configurado. Define CHILEMON_NODE o config/local.php => local_node'
    );
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

        // Extraer directorio base ignorando sufijos conocidos como /public y /api
        $scriptDir = dirname($scriptName);
        if (preg_match('#^(.*?)(?:/public)?(?:/api)?(?:/[^/]+\.php)$#i', $scriptName, $m)) {
            $scriptDir = $m[1];
        }

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

/**
 * -----------------------------------------------------
 * Asterisk AMI Configuration
 * -----------------------------------------------------
 * Parámetros de conexión al Asterisk Manager Interface.
 * Usado por ChileMon para controlar nodos ASL.
 */

if (!defined('AMI_HOST')) {
    define(
        'AMI_HOST',
        getenv('CHILEMON_AMI_HOST')
            ?: ($localConfig['ami_host'] ?? '127.0.0.1')
    );
}

if (!defined('AMI_PORT')) {
    define(
        'AMI_PORT',
        (int) (
            getenv('CHILEMON_AMI_PORT')
                ?: ($localConfig['ami_port'] ?? 5038)
        )
    );
}

if (!defined('AMI_USER')) {
    define(
        'AMI_USER',
        getenv('CHILEMON_AMI_USER')
            ?: ($localConfig['ami_user'] ?? 'admin')
    );
}

if (!defined('AMI_PASS')) {
    define(
        'AMI_PASS',
        getenv('CHILEMON_AMI_PASS')
            ?: ($localConfig['ami_pass'] ?? '')
    );
}

/**
 * Validación: en producción, AMI_HOST y AMI_PASS no pueden estar vacíos.
 * local.php debe proporcionarlos.
 */
if (AMI_PASS === '' && !$isCli) {
    throw new RuntimeException(
        'AMI_PASS no está configurado. Define CHILEMON_AMI_PASS o agregalo en config/local.php => ami_pass'
    );
}

if (!defined('AMI_TIMEOUT')) {
    define(
        'AMI_TIMEOUT',
        (int) ($localConfig['ami_timeout'] ?? 3)
    );
}

/**
 * -----------------------------------------------------
 * WebRTC Audio Bridge Configuration
 * -----------------------------------------------------
 * Parámetros de conexión para el bridge WebRTC ↔ IAX2.
 * El bridge es un daemon Python que corre junto a Asterisk.
 */

if (!defined('WEBRTC_PORT')) {
    define(
        'WEBRTC_PORT',
        (int) (
            getenv('CHILEMON_WEBRTC_PORT')
                ?: ($localConfig['webrtc_port'] ?? 9091)
        )
    );
}

if (!defined('IAX_PHONE_USER')) {
    define(
        'IAX_PHONE_USER',
        getenv('CHILEMON_IAX_PHONE_USER')
            ?: ($localConfig['iax_phone_user'] ?? 'webrtc-bridge')
    );
}

if (!defined('IAX_PHONE_PASS')) {
    define(
        'IAX_PHONE_PASS',
        getenv('CHILEMON_IAX_PHONE_PASS')
            ?: ($localConfig['iax_phone_pass'] ?? '')
    );
}

if (!defined('WEBRTC_SECRET')) {
    define(
        'WEBRTC_SECRET',
        getenv('CHILEMON_WEBRTC_SECRET')
            ?: ($localConfig['webrtc_secret'] ?? '')
    );
}

/**
 * Validación: en producción, IAX_PHONE_PASS y WEBRTC_SECRET
 * deben estar configurados en config/local.php.
 */
if (IAX_PHONE_PASS === '' && !$isCli) {
    throw new RuntimeException(
        'IAX_PHONE_PASS no está configurado. Defínelo en config/local.php => iax_phone_pass'
    );
}

if (WEBRTC_SECRET === '' && !$isCli) {
    throw new RuntimeException(
        'WEBRTC_SECRET no está configurado. Defínelo en config/local.php => webrtc_secret'
    );
}

if (!defined('RATE_LIMIT_WHITELIST')) {
    /**
     * Lista de IPs que NO están sujetas a rate limiting.
     * Vacío = rate limiting para todos (default).
     * Ejemplo en local.php: 'rate_limit_whitelist' => ['127.0.0.1', '192.168.1.100'],
     */
    $rawRlWhitelist = $localConfig['rate_limit_whitelist'] ?? [];
    if (is_string($rawRlWhitelist)) {
        $rawRlWhitelist = array_map('trim', explode(',', $rawRlWhitelist));
    }
    define('RATE_LIMIT_WHITELIST', is_array($rawRlWhitelist) ? $rawRlWhitelist : []);
}

if (!defined('NODE_WHITELIST')) {
    /**
     * Lista de IDs de nodos permitidos para connect/disconnect.
     * Vacío = todos los nodos permitidos (backwards compatible).
     * Ejemplo en local.php: 'node_whitelist' => ['494780', '123456'],
     */
    $rawWhitelist = $localConfig['node_whitelist'] ?? [];
    if (is_string($rawWhitelist)) {
        // Permitir comma-separated string por simplicidad
        $rawWhitelist = array_map('trim', explode(',', $rawWhitelist));
    }
    define('NODE_WHITELIST', is_array($rawWhitelist) ? $rawWhitelist : []);
}