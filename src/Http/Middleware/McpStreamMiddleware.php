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
 *
 * Supports both path-relative and RFC 8414 canonical well-known URI formats:
 *   Path-relative: /mcp/{service}/.well-known/oauth-authorization-server
 *   RFC 8414:      /.well-known/oauth-authorization-server/mcp/{service}
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
        'oauth-complete' => 'oauthComplete',
        'login' => 'login',
        'df-callback' => 'dfCallback',
        'token' => 'token',
    ];

    /**
     * RFC 8414 well-known endpoint types and their controller methods
     */
    private const RFC8414_WELL_KNOWN = [
        'oauth-authorization-server' => 'authorizationServerMetadata',
        'oauth-protected-resource' => 'protectedResourceMetadata',
    ];

    /**
     * CORS headers for MCP endpoints
     */
    // MCP clients (Claude Desktop, etc.) are external — CORS must be permissive.
    // Endpoints are protected by requiring a DreamFactory session/Bearer token.
    private static function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, POST, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, mcp-session-id',
            'Access-Control-Expose-Headers' => 'WWW-Authenticate',
        ];
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();
        $method = $request->method();

        // nginx auth_request subrequest for the SSE stream. Deliberately not
        // under /mcp/ so it cannot collide with a service named "authz" and so
        // nginx can point at one fixed internal location. The service name
        // travels in a header because the subrequest URI is fixed.
        if ($path === 'mcp-authz') {
            return $this->handleAuthz($request);
        }

        // Check for RFC 8414 canonical well-known paths first:
        //   /.well-known/oauth-authorization-server/mcp/{service}
        //   /.well-known/oauth-protected-resource/mcp/{service}
        if (preg_match('#^\.well-known/(oauth-authorization-server|oauth-protected-resource)/mcp/([A-Za-z0-9_\-]+)$#', $path, $wkMatches)) {
            return $this->handleRfc8414WellKnown($request, $next, $wkMatches[1], $wkMatches[2], $method);
        }

        // Check if this is an MCP path
        $isMcpPath = preg_match('#^mcp/([A-Za-z0-9_\-]+)(?:/(.+))?$#', $path, $matches);

        if (!$isMcpPath) {
            return $next($request);
        }

        // Handle OPTIONS preflight for any MCP path
        if ($method === 'OPTIONS') {
            return response('', 200)->withHeaders(self::corsHeaders());
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
            foreach (self::corsHeaders() as $key => $value) {
                $response->headers->set($key, $value);
            }
            return self::stripBodyForHead($response, $method);
        }

        return $next($request);
    }

    /**
     * Resolve the nginx auth_request subrequest for GET /mcp/{service}.
     */
    private function handleAuthz(Request $request)
    {
        $originalUri = (string) $request->header('X-Mcp-Original-Uri', '');
        if (!preg_match('#^/mcp/([A-Za-z0-9_\-]+)#', $originalUri, $m)) {
            return response()->json(['error' => 'Invalid or missing X-Mcp-Original-Uri'], 400);
        }

        $mcpService = $m[1];
        $request->attributes->set('mcp_service_name', $mcpService);
        $request->attributes->set('mcp_service_config', $this->getServiceConfig($mcpService));

        return (new McpStreamController())->authz($request, $mcpService);
    }

    /**
     * Handle RFC 8414 canonical well-known paths
     * e.g., /.well-known/oauth-authorization-server/mcp/{service}
     */
    private function handleRfc8414WellKnown(Request $request, Closure $next, string $wellKnownType, string $mcpService, string $method)
    {
        if ($method === 'OPTIONS') {
            return response('', 200)->withHeaders(self::corsHeaders());
        }

        $controllerMethod = self::RFC8414_WELL_KNOWN[$wellKnownType] ?? null;
        if ($controllerMethod && in_array($method, ['GET', 'HEAD'], true)) {
            $serviceConfig = $this->getServiceConfig($mcpService);
            $request->attributes->set('mcp_service_name', $mcpService);
            $request->attributes->set('mcp_service_config', $serviceConfig);

            $controller = new McpOAuthController();
            $response = $controller->$controllerMethod($request, $mcpService);

            if ($response) {
                foreach (self::corsHeaders() as $key => $value) {
                    $response->headers->set($key, $value);
                }
                return self::stripBodyForHead($response, $method);
            }
        }

        return $next($request);
    }

    /**
     * A HEAD response carries the same status and headers as GET but no body
     * (RFC 9110 sec. 9.3.2). The middleware returns responses directly rather than
     * through the router, so Symfony's own prepare() body-stripping never runs.
     */
    private static function stripBodyForHead($response, string $method)
    {
        if ($method === 'HEAD' && method_exists($response, 'setContent')) {
            $response->setContent('');
        }

        return $response;
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
            'HEAD' => $controller->handleHead($request, $mcpService),
            'POST' => $controller->handlePost($request, $mcpService),
            'DELETE' => $controller->handleDelete($request, $mcpService),
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
