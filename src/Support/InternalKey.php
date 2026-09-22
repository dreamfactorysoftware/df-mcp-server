<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Support;

/**
 * The shared secret DreamFactory sends to the MCP daemons as X-Mcp-Internal-Key.
 *
 * MCP_INTERNAL_KEY wins. Otherwise a random key is generated once and persisted
 * to storage/framework/mcp_internal_key (0600), which the data daemon reads, so
 * the gate is on with no configuration. Not storage/app: that directory is the
 * root of the stock "files" service and a key written there is downloadable.
 * Framework-free so it runs in a standalone PHPUnit checkout.
 */
final class InternalKey
{
    public const FILE = 'framework/mcp_internal_key';

    /** @return string the key, or '' when none is configured and the file cannot be written */
    public static function resolve(?string $configured, string $keyFile): string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }
        $existing = self::read($keyFile);
        if ($existing !== '') {
            return $existing;
        }

        $dir = dirname($keyFile);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }
        // tempnam creates the file 0600 in the same directory, so the link/rename
        // below is atomic and neither the daemon nor a concurrent request ever sees
        // a partial key.
        $tmp = @tempnam($dir, '.mcp_internal_key.');
        if ($tmp === false) {
            return '';
        }
        try {
            if (@file_put_contents($tmp, bin2hex(random_bytes(32))) === false) {
                return '';
            }
            @chmod($tmp, 0600);
            // link() never overwrites: when two first requests race, the first key
            // to land wins and both return it. rename() is the fallback for
            // filesystems without hard links.
            if (!@link($tmp, $keyFile) && !is_file($keyFile)) {
                @rename($tmp, $keyFile);
            }
        } finally {
            @unlink($tmp);
        }

        return self::read($keyFile);
    }

    private static function read(string $keyFile): string
    {
        return is_file($keyFile) ? trim((string) @file_get_contents($keyFile)) : '';
    }
}
