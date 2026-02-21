<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once ROOT_PATH . '/app/Core/Database.php';
require_once ROOT_PATH . '/app/Auth/Auth.php';

use App\Auth\Auth;

Auth::requireLogin();
