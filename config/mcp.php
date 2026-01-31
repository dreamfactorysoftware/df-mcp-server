<?php

return [
    // Daemon configuration
    'daemon' => [
        'enabled' => env('MCP_DAEMON_ENABLED', true),
        'url' => env('MCP_DAEMON_URL', 'http://127.0.0.1:8006'),
        'host' => env('MCP_DAEMON_HOST', '127.0.0.1'),
        'port' => env('MCP_DAEMON_PORT', 8006),
        // Internal base URL for daemon-to-API calls (when external port differs from internal, e.g. Docker)
        'internal_base_url' => env('MCP_INTERNAL_BASE_URL'),
    ],
];
