<?php

namespace DreamFactory\Core\McpServer\Models;

/**
 * Config model for the `system_mcp` ("System API MCP Server") service type.
 *
 * Shares the mcp_server_config table and OAuth/app/disabled_tools columns
 * with McpServerConfig, but the system server never runs custom tools, so
 * `custom_tools` is always reported empty and dropped on save.
 *
 * Exposed Services / scope_tools are likewise meaningless here — the system
 * daemon exposes /api/v2/system/* itself and never auto-mounts DB/file
 * services (SystemMcp::resolveAvailableServices() returns []). Both keys are
 * stripped from get/set/store, the Exposed Services picker is hidden from the
 * admin schema, and the empty-exposed-services save warning is suppressed.
 */
class SystemMcpServerConfig extends McpServerConfig
{
    /**
     * {@inheritdoc}
     *
     * Adds `exposed_services`: the multi_picklist only makes sense for the
     * data daemon's DB/file tool catalog.
     */
    protected static $schemaHiddenFields = [
        'app_id',
        'disabled_tools',
        'custom_tools',
        'scope_tools',
        'exposed_services',
        // The system daemon has no DB/file write verbs for the switch to hide.
        'allow_writes',
    ];

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
        unset($config['exposed_services'], $config['scope_tools']);

        return $config;
    }

    /**
     * Drop custom_tools so nothing is ever synced to mcp_custom_tools, and the
     * data-daemon-only scoping keys.
     */
    public static function setConfig($id, $config, $local_config = null)
    {
        unset($config['custom_tools']);
        unset($config['exposed_services'], $config['scope_tools']);

        return parent::setConfig($id, $config, $local_config);
    }

    /**
     * Drop custom_tools so nothing is ever synced to mcp_custom_tools, and the
     * data-daemon-only scoping keys.
     */
    public static function storeConfig($id, $config)
    {
        unset($config['custom_tools']);
        unset($config['exposed_services'], $config['scope_tools']);

        parent::storeConfig($id, $config);
    }

    /**
     * Never warn about empty Exposed Services: the system daemon has no
     * DB/file tool catalog for it to scope.
     */
    protected static function warnIfEmptyExposed($id, array $config): void
    {
        // Intentionally empty.
    }
}
