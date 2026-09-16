<?php

namespace DreamFactory\Core\McpServer\Models;

/**
 * Config model for the `system_mcp` ("System API MCP Server") service type.
 *
 * Shares the mcp_server_config table and OAuth/app/disabled_tools columns
 * with McpServerConfig, but the system server never runs custom tools, so
 * `custom_tools` is always reported empty and dropped on save.
 */
class SystemMcpServerConfig extends McpServerConfig
{
    /**
     * Always report an empty custom_tools list — the system server has none.
     *
     * NOTE: must use a forwarding call (parent::) so late static binding keeps
     * `static::whereServiceId()` in BaseServiceConfigModel bound to THIS class;
     * calling the base class by its explicit name would rebind `static` to the abstract
     * base and fatal with "Cannot instantiate abstract class".
     */
    public static function getConfig($id, $local_config = null, $protect = true)
    {
        $config = parent::getConfig($id, $local_config, $protect);
        $config['custom_tools'] = [];

        return $config;
    }

    /**
     * Drop custom_tools so nothing is ever synced to mcp_custom_tools.
     */
    public static function setConfig($id, $config, $local_config = null)
    {
        unset($config['custom_tools']);

        return parent::setConfig($id, $config, $local_config);
    }

    /**
     * Drop custom_tools so nothing is ever synced to mcp_custom_tools.
     */
    public static function storeConfig($id, $config)
    {
        unset($config['custom_tools']);

        parent::storeConfig($id, $config);
    }
}
