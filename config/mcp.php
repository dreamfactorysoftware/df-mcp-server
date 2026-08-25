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
        // Shared secret the PHP proxy sends to the daemon on every request so
        // the daemon can confirm the call came from the proxy (post-RBAC) and
        // not another local process. When unset, the PHP side generates a key
        // and persists it to the file below; the daemon reads the same file.
        'internal_key' => env('MCP_INTERNAL_KEY'),
        // Location of the generated shared-secret file. Both the PHP side and
        // the daemon default to storage/app/mcp_internal_key; override only if
        // the daemon cannot resolve the app storage path on its own.
        'internal_key_file' => env('MCP_INTERNAL_KEY_FILE'),

        // Serve the optional server-initiated SSE stream (GET on the MCP
        // endpoint). Off by default: PHP-FPM holds one worker for the entire
        // life of a stream, so a handful of connected clients can exhaust the
        // pool. The MCP spec explicitly allows answering GET with 405, and the
        // daemon emits no server-initiated messages today, so nothing is lost.
        // Only enable this when the stream is served by something evented —
        // e.g. nginx proxying /mcp straight to the daemon — not through FPM.
        'sse_enabled' => env('MCP_SSE_ENABLED', false),
    ],

    // Per-tool-call audit log (mcp_request_log table)
    'audit_logging' => [
        'enabled'        => env('MCP_AUDIT_LOGGING_ENABLED', true),
        'retention_days' => (int) env('MCP_AUDIT_RETENTION_DAYS', 90),
    ],
];
