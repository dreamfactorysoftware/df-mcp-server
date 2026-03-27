<?php

namespace DreamFactory\Core\McpServer;

use DreamFactory\Core\McpServer\Models\McpServerConfig;
use DreamFactory\Core\McpServer\Services\Mcp;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /**
     * Track if middleware has been registered to prevent duplicates
     */
    private static bool $middlewareRegistered = false;

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

        // Register middleware in register() to ensure it's registered as early as possible
        // This is critical for GET requests which DreamFactory intercepts before boot()
        if (!self::$middlewareRegistered) {
            $this->app->booting(function () {
                if (self::$middlewareRegistered) {
                    return;
                }
                try {
                    $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
                    if (method_exists($kernel, 'prependMiddleware')) {
                        $kernel->prependMiddleware(\DreamFactory\Core\McpServer\Http\Middleware\McpStreamMiddleware::class);
                        self::$middlewareRegistered = true;
                    } elseif (method_exists($kernel, 'pushMiddleware')) {
                        $kernel->pushMiddleware(\DreamFactory\Core\McpServer\Http\Middleware\McpStreamMiddleware::class);
                        self::$middlewareRegistered = true;
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to register MCP stream middleware', [
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load MCP routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/mcp.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mcp');
    }
}

