<?php

namespace DreamFactory\Core\McpServer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;
use DreamFactory\Core\McpServer\Http\Controllers\McpOAuthController;

/**
 * Middleware to intercept MCP requests before DreamFactory's routing
 * This ensures /mcp/* requests are handled by our controllers, not DF's API routing
 */
class McpStreamMiddleware
{
    /**
     * OAuth sub-paths handled by McpOAuthController
     */
    private const OAUTH_PATHS = [
        '.well-known/oauth-protected-resource' => 'protectedResourceMetadata',
        '.well-known/oauth-authorization-server' => 'authorizationServerMetadata',
        'register' => 'register',
        'authorize' => 'authorizeGet',
        'oauth-callback' => 'oauthCallback',
        'login' => 'login',
        'df-callback' => 'dfCallback',
        'token' => 'token',
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
                return $this->handleOAuth($request, $mcpService, $subPath, $method);
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
     * Handle OAuth requests (sub-paths)
     */
    private function handleOAuth(Request $request, string $mcpService, string $subPath, string $method)
    {
        $controller = new McpOAuthController();

        if ($method === 'OPTIONS') {
            return $controller->handleOptions($request, $mcpService);
        }

        $action = self::OAUTH_PATHS[$subPath] ?? null;
        if (!$action) {
            return null;
        }

        return $controller->$action($request, $mcpService);
    }

    /**
     * Check if the sub-path is an OAuth-related path
     */
    private function isOAuthPath(string $subPath): bool
    {
        return isset(self::OAUTH_PATHS[$subPath]);
    }
}

