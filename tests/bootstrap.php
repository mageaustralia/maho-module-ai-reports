<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'MageAustralia_AiReports_')) {
        return;
    }
    $relative = str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php';
    $path = __DIR__ . '/../src/app/code/community/' . $relative;
    if (is_file($path)) {
        require $path;
    }
});
