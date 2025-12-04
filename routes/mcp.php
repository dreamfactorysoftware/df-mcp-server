<?php

use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;
use DreamFactory\Core\McpServer\Http\Controllers\McpOAuthProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MCP Routes (Fallback - Middleware handles these first)
|--------------------------------------------------------------------------
|
| NOTE: These routes are typically intercepted by McpStreamMiddleware
| BEFORE Laravel routing kicks in. The middleware handles all /mcp/*
| requests directly to bypass DreamFactory's API routing.
|
| These routes serve as:
| 1. Documentation of available endpoints
| 2. Fallback if middleware isn't registered
| 3. Route listing via `php artisan route:list`
|
| Available endpoints:
|
| OAuth Discovery (no auth):
|   GET  /mcp/{service}/.well-known/oauth-protected-resource
|   GET  /mcp/{service}/.well-known/oauth-authorization-server
|
| OAuth Flow (no auth):
|   POST /mcp/{service}/register
|   GET  /mcp/{service}/authorize
|   POST /mcp/{service}/login
|   POST /mcp/{service}/df-callback
|   POST /mcp/{service}/token
|
| MCP Protocol (requires Bearer token):
|   GET/POST /mcp/{service}
|
*/

Route::group(['prefix' => 'mcp', 'middleware' => []], function () {
    // OAuth Discovery
    Route::get('{mcpService}/.well-known/oauth-protected-resource', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/.well-known/oauth-authorization-server', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');

    // OAuth Flow
    Route::post('{mcpService}/register', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/authorize', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}/login', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}/df-callback', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}/token', [McpOAuthProxyController::class, 'proxy'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::options('{mcpService}/token', [McpOAuthProxyController::class, 'handleOptions'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');

    // MCP Protocol
    Route::match(['get', 'post'], '{mcpService}', [McpStreamController::class, 'handlePost'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::options('{mcpService}', [McpStreamController::class, 'handleOptions'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
})->withoutMiddleware(['df.api']);
