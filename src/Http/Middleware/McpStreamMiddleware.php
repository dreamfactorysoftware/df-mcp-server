<?php

namespace DreamFactory\Core\McpServer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;
use DreamFactory\Core\McpServer\Http\Controllers\McpOAuthController;

/**
 * Middleware to intercept MCP requests before DreamFactory's routing
 * This ensures /mcp/* requests are handled by our controllers, not DF's API routing
 * Also handles CORS for all MCP endpoints and loads service configuration
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
     * CORS headers for MCP endpoints
     */
    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, mcp-session-id',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();
        $method = $request->method();

        // Check if this is an MCP path
        $isMcpPath = preg_match('#^mcp/([A-Za-z0-9_\-]+)(?:/(.+))?$#', $path, $matches);

        if (!$isMcpPath) {
            return $next($request);
        }

        // Handle OPTIONS preflight for any MCP path
        if ($method === 'OPTIONS') {
            return response('', 200)->withHeaders(self::CORS_HEADERS);
        }

        $mcpService = $matches[1];
        $subPath = $matches[2] ?? null;

        // Load service config and store in request for controllers
        $serviceConfig = $this->getServiceConfig($mcpService);
        $request->attributes->set('mcp_service_name', $mcpService);
        $request->attributes->set('mcp_service_config', $serviceConfig);

        // Route to appropriate handler
        if ($subPath === null) {
            // Exact /mcp/{service} path - MCP protocol endpoint
            $response = $this->handleMcpProtocol($request, $mcpService, $method);
        } elseif ($this->isOAuthPath($subPath)) {
            // OAuth sub-path
            $response = $this->handleOAuth($request, $mcpService, $subPath, $method);
        } else {
            return $next($request);
        }

        // Add CORS headers to response
        if ($response) {
            foreach (self::CORS_HEADERS as $key => $value) {
                $response->headers->set($key, $value);
            }
            return $response;
        }

        return $next($request);
    }

    /**
     * Get service configuration from ServiceManager
     */
    private function getServiceConfig(string $mcpService): ?array
    {
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $serviceManager */
            $serviceManager = app('df.service');
            $service = $serviceManager->getService($mcpService);

            if (method_exists($service, 'getConfig')) {
                return $service->getConfig();
            }
        } catch (\Throwable $e) {
            Log::error('Failed to get service config', [
                'mcpService' => $mcpService,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
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
            default => null,
        };
    }

    /**
     * Handle OAuth requests (sub-paths)
     */
    private function handleOAuth(Request $request, string $mcpService, string $subPath, string $method)
    {
        $controller = new McpOAuthController();

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

