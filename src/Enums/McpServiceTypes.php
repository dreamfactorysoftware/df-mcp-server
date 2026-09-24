<?php

namespace DreamFactory\Core\McpServer\Enums;

/**
 * Service type names registered by this package.
 *
 * DATA   — the original "MCP Server Service": exposes DB/file services
 *          (tables, schema, procedures, files) through the Node data daemon.
 * SYSTEM — "System API MCP Server": exposes /api/v2/system/* (services,
 *          roles, apps/API keys, admins, environment) through the separate
 *          df-system-mcp-server daemon so AI clients can administer DF.
 */
final class McpServiceTypes
{
    public const DATA = 'mcp';
    public const SYSTEM = 'system_mcp';

    /** @return string[] */
    public static function all(): array
    {
        return [self::DATA, self::SYSTEM];
    }

    public static function isSystem(?string $type): bool
    {
        return $type === self::SYSTEM;
    }
}
