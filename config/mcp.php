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
        // Seconds a PHP worker waits for the daemon to answer one proxied MCP call.
        'timeout' => (int) env('MCP_DAEMON_TIMEOUT', 300),
        // Shared secret sent as X-Mcp-Internal-Key to BOTH daemons. The data
        // daemon always requires it. When unset, DreamFactory generates one and
        // writes it to internal_key_file, which the data daemon reads; set it
        // explicitly (same value on the daemons) when a daemon cannot read that
        // file, e.g. a sidecar container.
        'internal_key' => env('MCP_INTERNAL_KEY'),
        // Where the generated key lives. Default storage/framework/mcp_internal_key.
        // Never under storage/app: that is the stock "files" service root and is
        // downloadable over the REST API.
        'internal_key_file' => env('MCP_INTERNAL_KEY_FILE'),
    ],

    // System API MCP daemon (df-system-mcp-server) backing the `system_mcp`
    // service type. Exposes /api/v2/system/* as MCP tools. By default it runs on
    // this host (scripts/start-system-daemon.sh) at the URL below.
    'system_daemon' => [
        'enabled' => env('MCP_SYSTEM_DAEMON_ENABLED', true),
        'url' => env('MCP_SYSTEM_DAEMON_URL', 'http://127.0.0.1:3700'),
        // DreamFactory base URL the daemon calls back. Leave unset when the daemon runs on
        // this host. For a sidecar container set an address it can reach (e.g. http://web).
        // Falls back to daemon.internal_base_url, then to the incoming request's origin.
        'base_url' => env('MCP_SYSTEM_DAEMON_BASE_URL'),
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
