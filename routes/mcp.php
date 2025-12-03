<?php

use DreamFactory\Core\McpServer\Http\Controllers\McpStreamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MCP Stream Routes
|--------------------------------------------------------------------------
|
| These routes handle MCP protocol requests (both GET SSE and POST JSON-RPC)
| and bypass the standard DreamFactory REST layer to work directly with
| the MCP SDK. This allows proper SSE streaming support.
|
*/

Route::group(['prefix' => 'mcp', 'middleware' => []], function () {
    Route::get('{mcpService}', [McpStreamController::class, 'handleGet'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');

    Route::options('{mcpService}', [McpStreamController::class, 'handleOptions'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');

    Route::post('{mcpService}', [McpStreamController::class, 'handlePost'])
        ->where('mcpService', '[A-Za-z0-9_\-]+');
})->withoutMiddleware(['df.api']);
