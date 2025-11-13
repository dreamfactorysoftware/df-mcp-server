<?php

use DreamFactory\Core\McpServer\Http\Controllers\McpHttpController;
use DreamFactory\Core\McpServer\Http\Controllers\McpRegistryController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('df.api_route_prefix', 'api'))
    ->middleware('df.api')
    ->group(function () {
        Route::post('/mcp/servers', [McpRegistryController::class, 'store'])->name('mcp.servers.store');
        Route::get('/mcp/servers', [McpRegistryController::class, 'index'])->name('mcp.servers.index');
        Route::delete('/mcp/servers/{apiName}', [McpRegistryController::class, 'destroy'])
            ->where('apiName', '[A-Za-z0-9_\-]+')
            ->name('mcp.servers.destroy');

        Route::any('/mcp/{apiName}', [McpHttpController::class, 'handle'])
            ->where('apiName', '[A-Za-z0-9_\-]+')
            ->name('mcp.runtime');
    });

