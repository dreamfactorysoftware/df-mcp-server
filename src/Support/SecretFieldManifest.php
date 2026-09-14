<?php

namespace DreamFactory\Core\McpServer\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Which config fields of each DreamFactory service type hold secrets, for df-system-mcp-server.
 *
 * The System API MCP daemon only sees REST responses, and those don't say which config fields
 * are secret: the config schema types almost every encrypted field as plain text or string.
 * DreamFactory's model metadata does say it, so this builds the list from each service type's
 * config handler:
 *
 * - `secret`: the model's `$encrypted` and `$protected` fields, plus schema fields typed
 *   password or certificate, minus READABLE_FIELDS (encrypted identifiers that debugging a
 *   connection needs to see)
 * - `maps`: schema fields typed `object`, i.e. user-named key/value maps such as a script
 *   service's `config` or SOAP/curl `options`; the daemon masks their values by key name
 *
 * The manifest goes to the daemon in the proxy envelope (`_mcpSecretFields`) and only adds
 * masking on top of the daemon's name rules. getConfigSchema() reads table schemas, so the
 * result is cached.
 */
final class SecretFieldManifest
{
    /** Encrypted at rest, but identifiers rather than secrets. */
    public const READABLE_FIELDS = ['username', 'account_name'];

    /** Schema field types whose value is secret material. */
    public const SECRET_SCHEMA_TYPES = ['password', 'file_certificate', 'file_certificate_api'];

    public const CACHE_KEY = 'mcp:system_daemon:secret_fields';
    public const CACHE_SECONDS = 3600;

    /**
     * The manifest for every registered service type, cached. Empty (the daemon falls back to
     * its name rules) if it can't be built.
     *
     * @return array<string, array{secret: string[], maps: string[]}>
     */
    public static function cached(): array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                self::CACHE_SECONDS,
                static fn () => self::build(\ServiceManager::getServiceTypes())
            );
        } catch (\Throwable $e) {
            Log::warning('System API MCP: could not build the secret field manifest', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param iterable<object> $serviceTypes objects with getName() and getConfigHandler()
     * @return array<string, array{secret: string[], maps: string[]}> types with at least one entry, sorted by name
     */
    public static function build(iterable $serviceTypes): array
    {
        $manifest = [];
        foreach ($serviceTypes as $type) {
            $handler = $type->getConfigHandler();
            if (!is_string($handler) || !class_exists($handler)) {
                continue;
            }
            try {
                $schema = $handler::getConfigSchema();
            } catch (\Throwable $e) {
                $schema = [];
            }
            $entry = self::forHandler($handler, is_array($schema) ? $schema : []);
            if ($entry['secret'] || $entry['maps']) {
                $manifest[(string) $type->getName()] = $entry;
            }
        }
        ksort($manifest);

        return $manifest;
    }

    /**
     * @param class-string $handler config model class
     * @param array $schema its getConfigSchema() result
     * @return array{secret: string[], maps: string[]}
     */
    public static function forHandler(string $handler, array $schema): array
    {
        $fields = array_merge(self::listProperty($handler, 'encrypted'), self::listProperty($handler, 'protected'));
        $maps = [];
        foreach ($schema as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null)) {
                continue;
            }
            $type = $field['type'] ?? null;
            if (in_array($type, self::SECRET_SCHEMA_TYPES, true)) {
                $fields[] = $field['name'];
            } elseif ($type === 'object') {
                $maps[] = $field['name'];
            }
        }

        $secret = array_values(array_unique(array_diff($fields, self::READABLE_FIELDS)));
        sort($secret);
        $maps = array_values(array_unique($maps));
        sort($maps);

        return ['secret' => $secret, 'maps' => $maps];
    }

    /** The class's effective default for a list property such as `$encrypted`. */
    private static function listProperty(string $class, string $property): array
    {
        $defaults = (new \ReflectionClass($class))->getDefaultProperties();
        $value = $defaults[$property] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
