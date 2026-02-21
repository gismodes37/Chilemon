<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

return [
    'driver' => 'sqlite',
    'sqlite' => [
        // usa tus constantes actuales definidas en app.php
        'path' => DATA_PATH . '/chilemon.sqlite',
    ],
];
