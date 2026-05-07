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

// Minimal stub so Helper classes that extend Mage_Core_Helper_Abstract can be
// loaded without a full Mage bootstrap in unit tests.
if (!class_exists('Mage_Core_Helper_Abstract')) {
    class Mage_Core_Helper_Abstract
    {
        public function __(): string { return ''; }
    }
}
