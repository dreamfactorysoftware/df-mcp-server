<?php

namespace DreamFactory\Core\McpServer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;

/**
 * Middleware to intercept MCP stream requests before DreamFactory's routing
 * This ensures GET requests to /mcp/* are handled by our controller
 */
class McpStreamMiddleware
{
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
        $uri = $request->getRequestUri();
        
        $mcpService = null;
        if (preg_match('#^mcp/([A-Za-z0-9_\-]+)$#', $path, $matches)) {
            $mcpService = $matches[1];
        } elseif (preg_match('#/mcp/([A-Za-z0-9_\-]+)#', $uri, $matches)) {
            $mcpService = $matches[1];
        }
        
        if ($mcpService) {
            $controller = new McpStreamController();
            $method = $request->method();
            
            if ($method === 'GET') {
                return $controller->handleGet($request, $mcpService);
            } elseif ($method === 'POST') {
                return $controller->handlePost($request, $mcpService);
            } elseif ($method === 'OPTIONS') {
                return $controller->handleOptions($request, $mcpService);
            }
        }
        
        return $next($request);
    }
}

