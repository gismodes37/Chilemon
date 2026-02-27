<?php
declare(strict_types=1);

return [
  'driver' => 'sqlite',
  'sqlite' => [
    'path' => realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'chilemon.sqlite',
  ],
];