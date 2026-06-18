<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for ChileMon.
 *
 * Defines minimal constants for testing and loads the PSR-4 autoloader.
 * Does NOT load config/app.php to avoid production-level validation
 * (local.php check, ASL_NODE requirement, AMI_PASS check, etc.).
 */

// --- Paths ---

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', '/');
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost');
}

// --- Environment (skips local.php + production checks) ---

if (!defined('APP_ENV')) {
    define('APP_ENV', 'dev');
}

// --- Node (needed by classes that reference ASL_NODE) ---

if (!defined('ASL_NODE')) {
    define('ASL_NODE', '999999');
}

// --- Autoloader ---

require_once ROOT_PATH . '/app/autoload.php';

// --- Output buffering (tests may emit headers via session_start, etc.) ---

ob_start();
