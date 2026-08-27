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
 */

$vendorAutoload = getenv('DF_VENDOR_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

$map = [
    'DreamFactory\\Core\\McpServer\\Tests\\' => __DIR__ . '/',
    'DreamFactory\\Core\\McpServer\\'        => dirname(__DIR__) . '/src/',
];

spl_autoload_register(function (string $class) use ($map): void {
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
}, true, true);
