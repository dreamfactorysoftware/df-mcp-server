<?php

namespace DreamFactory\Core\McpServer;

use DreamFactory\Core\McpServer\Models\McpServerConfig;
use DreamFactory\Core\McpServer\Services\Mcp;
use Illuminate\Support\Facades\Route;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/mcp.php',
            'mcp'
        );

        // Add our scripting service types.
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType(
                    [
                        'name'            => 'mcp',
                        'label'           => 'MCP Server Service',
                        'description'     => 'MCP Server service for Model Context Protocol.',
                        'group'           => ServiceTypeGroups::MCP,
                        'config_handler'  => McpServerConfig::class,
                        'factory'         => function ($config) {
                            return new Mcp($config);
                        },
                    ]));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load routes
        if (!$this->app->routesAreCached()) {
            include __DIR__ . '/../routes/api.php';
        }
    }
}

