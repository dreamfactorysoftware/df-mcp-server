<?php

namespace DreamFactory\Core\McpServer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;
use DreamFactory\Core\McpServer\Http\Controllers\McpOAuthProxyController;

/**
 * Middleware to intercept MCP requests before DreamFactory's routing
 * This ensures /mcp/* requests are handled by our controllers, not DF's API routing
 */
class McpStreamMiddleware
{
    /**
     * OAuth sub-paths that should be proxied to daemon
     */
    private const OAUTH_PATHS = [
        '.well-known/oauth-protected-resource',
        '.well-known/oauth-authorization-server',
        'register',
        'authorize',
        'login',
        'df-callback',
        'token',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();
        $method = $request->method();

        // Check for exact /mcp/{service} path (MCP protocol endpoint)
        if (preg_match('#^mcp/([A-Za-z0-9_\-]+)$#', $path, $matches)) {
            return $this->handleMcpProtocol($request, $matches[1], $method);
        }

        // Check for OAuth sub-paths: /mcp/{service}/{oauth-path}
        if (preg_match('#^mcp/([A-Za-z0-9_\-]+)/(.+)$#', $path, $matches)) {
            $mcpService = $matches[1];
            $subPath = $matches[2];

            if ($this->isOAuthPath($subPath)) {
                return $this->handleOAuthProxy($request, $mcpService, $method);
            }
        }

        return $next($request);
    }

    /**
     * Handle MCP protocol requests (main endpoint)
     */
    private function handleMcpProtocol(Request $request, string $mcpService, string $method)
    {
        $controller = new McpStreamController();

        return match ($method) {
            'GET' => $controller->handleGet($request, $mcpService),
            'POST' => $controller->handlePost($request, $mcpService),
            'OPTIONS' => $controller->handleOptions($request, $mcpService),
            default => null,
        };
    }

    /**
     * Handle OAuth proxy requests (sub-paths)
     */
    private function handleOAuthProxy(Request $request, string $mcpService, string $method)
    {
        $controller = new McpOAuthProxyController();

        return match ($method) {
            'GET', 'POST' => $controller->proxy($request, $mcpService),
            'OPTIONS' => $controller->handleOptions($request, $mcpService),
            default => null,
        };
    }

    /**
     * Check if the sub-path is an OAuth-related path
     */
    private function isOAuthPath(string $subPath): bool
    {
        foreach (self::OAUTH_PATHS as $oauthPath) {
            if ($subPath === $oauthPath) {
                return true;
            }
        }
        return false;
    }
}

