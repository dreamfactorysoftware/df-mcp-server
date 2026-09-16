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
     * @return array{type:string, url:string, base_url:?string, enabled:bool, label:string, enabled_env:string, disabled_message:string}
     */
    public static function forServiceType(?string $type, ?array $mcpConfig = null): array
    {
        if ($mcpConfig === null) {
            $mcpConfig = function_exists('config') ? (array) config('mcp', []) : [];
        }
        $internalBase = $mcpConfig['daemon']['internal_base_url'] ?? null;

        if (McpServiceTypes::isSystem($type)) {
            $section = (array) ($mcpConfig['system_daemon'] ?? []);
            $label = 'System API MCP daemon';
            $enabledEnv = 'MCP_SYSTEM_DAEMON_ENABLED';
            $url = $section['url'] ?? self::SYSTEM_DEFAULT_URL;
            // On this host (the default) the daemon can reach DreamFactory the same way the
            // data daemon does. A sidecar container needs its own way back (base_url, e.g.
            // http://web); the shared internal base is the next best guess.
            $baseUrl = !empty($section['base_url']) ? $section['base_url'] : $internalBase;
            $enabled = self::toBool($section['enabled'] ?? true);
            $disabledMessage = $label . ' is disabled. Set ' . $enabledEnv . '=true and start df-system-mcp-server'
                . ' (scripts/start-system-daemon.sh, or its container).';
            $resolvedType = McpServiceTypes::SYSTEM;
        } else {
            $section = (array) ($mcpConfig['daemon'] ?? []);
            $label = 'MCP daemon';
            $enabledEnv = 'MCP_DAEMON_ENABLED';
            $url = $section['url'] ?? self::DATA_DEFAULT_URL;
            $baseUrl = $internalBase;
            $enabled = self::toBool($section['enabled'] ?? true);
            $disabledMessage = $label . ' is disabled. Please set ' . $enabledEnv . '=true and run the Node daemon.';
            $resolvedType = McpServiceTypes::DATA;
        }

        return [
            'type'             => $resolvedType,
            'url'              => rtrim((string) $url, '/'),
            'base_url'         => empty($baseUrl) ? null : rtrim((string) $baseUrl, '/'),
            'enabled'          => $enabled,
            'label'            => $label,
            'enabled_env'      => $enabledEnv,
            'disabled_message' => $disabledMessage,
        ];
    }

    /**
     * DreamFactory API base the daemon calls back (sent as X-Mcp-Base-Url): the
     * configured base URL for this target when set, else the incoming request's
     * origin — which only works when the daemon can reach DreamFactory there.
     */
    public static function apiBaseUrl(array $target, string $requestOrigin): string
    {
        $base = !empty($target['base_url']) ? $target['base_url'] : rtrim($requestOrigin, '/');

        return $base . '/api/v2';
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
