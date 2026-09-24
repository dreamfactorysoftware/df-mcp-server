<?php

/**
 * PHPUnit bootstrap.
 *
 * Chains the vendor autoloader (DF_VENDOR_AUTOLOAD, default ../vendor/autoload.php,
 * so the suite can run against a DreamFactory install's vendor tree) and then
 * PREPENDS a PSR-4 autoloader for THIS checkout's src/ and tests/, so the
 * package under test always wins even when the host app's vendor dir ships an
 * older df-mcp-server. Composer's own loader registers itself with prepend=true,
 * which is why ours must be registered after it.
 *
 * Standalone checkout (no vendor autoloader found): the src/ mapping is
 * limited to classes that are safe without Laravel — helpers in Utility and
 * Support, Enums, and the parentless Client (whose tested methods are pure).
 * Models and Services extend framework classes and must not be autoloaded
 * here — their tests need a real vendor tree (point DF_VENDOR_AUTOLOAD at one).
 */

$vendorAutoload = getenv('DF_VENDOR_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
$haveVendor = is_file($vendorAutoload);
if ($haveVendor) {
    require $vendorAutoload;
}

$map = [
    'DreamFactory\\Core\\McpServer\\Tests\\' => __DIR__ . '/',
    'DreamFactory\\Core\\McpServer\\'        => dirname(__DIR__) . '/src/',
];

// src/ sub-namespaces that do not touch the framework at class-load time.
$standaloneSafe = ['Utility\\', 'Support\\', 'Enums\\', 'Client\\'];

spl_autoload_register(function (string $class) use ($map, $haveVendor, $standaloneSafe): void {
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $rel = substr($class, strlen($prefix));
        if (!$haveVendor && $prefix === 'DreamFactory\\Core\\McpServer\\') {
            $safe = false;
            foreach ($standaloneSafe as $ns) {
                if (str_starts_with($rel, $ns)) {
                    $safe = true;
                    break;
                }
            }
            if (!$safe) {
                continue;
            }
        }
        $file = $dir . str_replace('\\', '/', $rel) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
}, true, true);
