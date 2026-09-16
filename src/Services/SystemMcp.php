<?php

namespace DreamFactory\Core\McpServer\Services;

/**
 * "System API MCP Server" service (type `system_mcp`).
 *
 * Same OAuth front door and /rpc bridge as {@see Mcp}, but proxied to the
 * df-system-mcp-server daemon (see DaemonTarget), which exposes
 * /api/v2/system/* as MCP tools. The daemon does not auto-expose DB/file
 * services, so no available-services list is resolved or sent.
 */
class SystemMcp extends Mcp
{
    /**
     * {@inheritdoc}
     */
    protected function resolveAvailableServices(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocPaths(): array
    {
        $paths = parent::getApiDocPaths();
        $paths['/']['get']['summary'] = 'Retrieve System API MCP service configuration';
        $paths['/']['get']['description'] = sprintf(
            'Returns the MCP endpoint (%s) AI clients use to administer this DreamFactory instance via the System API.',
            $this->getMcpEndpoint()
        );

        return $paths;
    }
}
