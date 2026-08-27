<?php

namespace DreamFactory\Core\McpServer\Support;

use DreamFactory\Core\McpServer\Enums\McpServiceTypes;

/**
 * Single place that decides which Node daemon a given MCP service type talks to.
 *
 *   system_mcp     -> config('mcp.system_daemon.*')  (df-system-mcp-server)
 *   anything else  -> config('mcp.daemon.*')         (bundled data daemon)
 */
final class DaemonTarget
{
    public const DATA_DEFAULT_URL = 'http://127.0.0.1:8006';
    public const SYSTEM_DEFAULT_URL = 'http://127.0.0.1:3700';

    /**
     * @param string|null $type      Service type name (e.g. 'mcp', 'system_mcp').
     * @param array|null  $mcpConfig The `mcp` config array. When null, the
     *                               Laravel config('mcp') is used. Injectable so
     *                               the resolver is testable without an app.
     *
     * @return array{type:string, url:string, enabled:bool, label:string, enabled_env:string, disabled_message:string}
     */
    public static function forServiceType(?string $type, ?array $mcpConfig = null): array
    {
        if ($mcpConfig === null) {
            $mcpConfig = function_exists('config') ? (array) config('mcp', []) : [];
        }

        if (McpServiceTypes::isSystem($type)) {
            $section = (array) ($mcpConfig['system_daemon'] ?? []);
            $label = 'System API MCP daemon';
            $enabledEnv = 'MCP_SYSTEM_DAEMON_ENABLED';
            $url = $section['url'] ?? self::SYSTEM_DEFAULT_URL;
            $enabled = self::toBool($section['enabled'] ?? true);
            $disabledMessage = $label . ' is disabled. Set ' . $enabledEnv . '=true and run df-system-mcp-server.';
            $resolvedType = McpServiceTypes::SYSTEM;
        } else {
            $section = (array) ($mcpConfig['daemon'] ?? []);
            $label = 'MCP daemon';
            $enabledEnv = 'MCP_DAEMON_ENABLED';
            $url = $section['url'] ?? self::DATA_DEFAULT_URL;
            $enabled = self::toBool($section['enabled'] ?? false);
            $disabledMessage = $label . ' is disabled. Please set ' . $enabledEnv . '=true and run the Node daemon.';
            $resolvedType = McpServiceTypes::DATA;
        }

        return [
            'type'             => $resolvedType,
            'url'              => rtrim((string) $url, '/'),
            'enabled'          => $enabled,
            'label'            => $label,
            'enabled_env'      => $enabledEnv,
            'disabled_message' => $disabledMessage,
        ];
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return (bool) $value;
    }
}
