<?php

declare(strict_types=1);

foreach ([dirname(__DIR__).'/vendor/autoload.php', dirname(__DIR__, 2).'/bagit/vendor/autoload.php', dirname(__DIR__, 3).'/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    foreach (['Survos\\AltoRaw\\' => dirname(__DIR__).'/src/', 'Survos\\BagIt\\' => dirname(__DIR__, 2).'/bagit/src/'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});
