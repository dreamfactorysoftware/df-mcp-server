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
        // Shared secret sent as X-Mcp-Internal-Key to BOTH daemons when set
        // (the daemons enforce it when MCP_INTERNAL_KEY is set on their side).
        'internal_key' => env('MCP_INTERNAL_KEY'),
    ],

    // System API MCP daemon (df-system-mcp-server) backing the `system_mcp`
    // service type. Exposes /api/v2/system/* as MCP tools.
    'system_daemon' => [
        'enabled' => env('MCP_SYSTEM_DAEMON_ENABLED', true),
        'url' => env('MCP_SYSTEM_DAEMON_URL', 'http://127.0.0.1:3700'),
    ],

    // Per-tool-call audit log (mcp_request_log table)
    'audit_logging' => [
        'enabled'        => env('MCP_AUDIT_LOGGING_ENABLED', true),
        'retention_days' => (int) env('MCP_AUDIT_RETENTION_DAYS', 90),
    ],
];
