<?php

return [
    // Cache TTL (seconds) for function bodies fetched from SCM services (GitHub/GitLab/Bitbucket)
    'scm_cache_ttl' => env('MCP_SCM_CACHE_TTL', 300),

    // Daemon configuration
    'daemon' => [
        'enabled' => env('MCP_DAEMON_ENABLED', true),
        'url' => env('MCP_DAEMON_URL', 'http://127.0.0.1:8006'),
        'host' => env('MCP_DAEMON_HOST', '127.0.0.1'),
        'port' => env('MCP_DAEMON_PORT', 8006),
        'internal_base_url' => env('MCP_INTERNAL_BASE_URL'),
    ],

    // Per-tool-call audit log (mcp_request_log table)
    'audit_logging' => [
        'enabled'        => env('MCP_AUDIT_LOGGING_ENABLED', true),
        'retention_days' => (int) env('MCP_AUDIT_RETENTION_DAYS', 90),
    ],

    // Default ON: tools/list only includes backends named in the MCP service's
    // `exposed_services` config, not every accessible database/file service.
    // Set MCP_SCOPE_TOOLS=false to restore the instance-wide catalog for
    // services that have not set `scope_tools` explicitly. An explicit
    // `exposed_services` list always applies regardless of this flag.
    // FILTER_VALIDATE_BOOLEAN so MCP_SCOPE_TOOLS=false does not become the
    // string "false" (truthy in PHP).
    'scope_tools' => filter_var(env('MCP_SCOPE_TOOLS', true), FILTER_VALIDATE_BOOLEAN),
];
