<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;

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
     * Skip the mcp_custom_tools lookup entirely — the system server has none.
     */
    public static function getConfig($id, $local_config = null, $protect = true)
    {
        $config = BaseServiceConfigModel::getConfig($id, $local_config, $protect);
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
