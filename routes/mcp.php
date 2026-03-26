<?php

use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;
use DreamFactory\Core\McpServer\Http\Controllers\McpOAuthController;
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
| The middleware also handles:
| - CORS headers for all MCP responses
| - OPTIONS preflight requests
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
|   GET  /mcp/{service}/oauth-callback
|   POST /mcp/{service}/login
|   POST /mcp/{service}/df-callback
|   POST /mcp/{service}/token
|
| MCP Protocol (requires Bearer token):
|   GET/POST /mcp/{service}
|
*/


// RFC 8414 canonical well-known paths (at domain root)
// Required for spec-compliant OAuth clients like Claude Desktop
Route::get('.well-known/oauth-protected-resource/mcp/{mcpService}', [McpOAuthController::class, 'protectedResourceMetadata'])
    ->where('mcpService', '[A-Za-z0-9_\-]+')
    ->withoutMiddleware(['df.api']);
Route::get('.well-known/oauth-authorization-server/mcp/{mcpService}', [McpOAuthController::class, 'authorizationServerMetadata'])
    ->where('mcpService', '[A-Za-z0-9_\-]+')
    ->withoutMiddleware(['df.api']);

Route::group(['prefix' => 'mcp', 'middleware' => []], function () {
    // OAuth Discovery
    Route::get('{mcpService}/.well-known/oauth-protected-resource', [McpOAuthController::class, 'protectedResourceMetadata'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/.well-known/oauth-authorization-server', [McpOAuthController::class, 'authorizationServerMetadata'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');

    // OAuth Flow
    Route::post('{mcpService}/register', [McpOAuthController::class, 'register'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/authorize', [McpOAuthController::class, 'authorizeGet'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/oauth-callback', [McpOAuthController::class, 'oauthCallback'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}/login', [McpOAuthController::class, 'login'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}/df-callback', [McpOAuthController::class, 'dfCallback'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/oauth-complete', [McpOAuthController::class, 'oauthComplete'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::get('{mcpService}/oauth-redirect', [McpOAuthController::class, 'oauthRedirect'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}/token', [McpOAuthController::class, 'token'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');

    // MCP Protocol
    Route::get('{mcpService}', [McpStreamController::class, 'handleGet'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::post('{mcpService}', [McpStreamController::class, 'handlePost'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
    Route::delete('{mcpService}', [McpStreamController::class, 'handleDelete'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
})->withoutMiddleware(['df.api']);
