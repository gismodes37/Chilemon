<?php
declare(strict_types=1);

/**
 * public/api/companion-uninstall.php
 * -----------------------------------------------
 * Delivers a self-contained uninstaller for the Windows Companion App.
 *
 * The dashboard runs on the Pi and cannot execute code on the PC, so it only
 * serves a double-clickable .bat. That script downloads the pinned source,
 * prints the exact plan with --dry-run, asks for local confirmation and only
 * then removes the per-user companion data. No IAX2 secret is involved here.
 */

require_once __DIR__ . '/../../config/app.php';
require_once ROOT_PATH . '/app/autoload.php';

use App\Auth\Auth;

Auth::startSession();
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Same pin as the installer: the uninstaller must run the code the user paired with.
$repo_ref = defined('CHILEMON_COMPANION_REF')
    ? (string) constant('CHILEMON_COMPANION_REF')
    : 'main';
$repo_ref = preg_replace('/[^A-Za-z0-9._\/-]/', '', $repo_ref) ?: 'main';

$template = ROOT_PATH . '/companion/uninstaller-template.bat.tpl';
$raw = @file_get_contents($template);
if ($raw === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Uninstaller template is unavailable.']);
    exit;
}

$payload = str_replace('__CHILEMON_REPO_REF__', $repo_ref, $raw);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="chilemon-companion-uninstall.bat"');
header('Content-Length: ' . (string) strlen($payload));
echo $payload;
exit;
