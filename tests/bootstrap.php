<?php

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
    return;
}

// Standalone checkout: load only Laravel-free helpers. Models extend Eloquent
// and must not be autoloaded here — those tests need the real vendor tree.
spl_autoload_register(static function (string $class): void {
    $prefix = 'DreamFactory\\Core\\McpServer\\Utility\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/src/Utility/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});
