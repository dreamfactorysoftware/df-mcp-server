<?php

return [
    'middleware' => [],
    
    'route_prefix' => null,

    'server_ttl_seconds' => 1800,

    'mcp_route_prefix' => 'mcp',

    // Daemon configuration
    'daemon' => [
        'enabled' => env('MCP_DAEMON_ENABLED', true),
        'url' => env('MCP_DAEMON_URL', 'http://127.0.0.1:8006'),
        'host' => env('MCP_DAEMON_HOST', '127.0.0.1'),
        'port' => env('MCP_DAEMON_PORT', 8006),
    ],
];

