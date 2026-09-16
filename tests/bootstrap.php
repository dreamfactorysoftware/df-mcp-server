<?php

$vendor = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
    return;
}

// Standalone checkout: load only classes that are safe without Laravel —
// helpers in Utility and the parentless Client (whose tested methods are
// pure). Models extend Eloquent and must not be autoloaded here — those
// tests need the real vendor tree.
spl_autoload_register(static function (string $class): void {
    $base = 'DreamFactory\\Core\\McpServer\\';
    if (!str_starts_with($class, $base)) {
        return;
    }
    $rel = substr($class, strlen($base));
    if (!str_starts_with($rel, 'Utility\\') && !str_starts_with($rel, 'Client\\')) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
