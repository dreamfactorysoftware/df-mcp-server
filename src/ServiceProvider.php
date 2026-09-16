<?php

namespace DreamFactory\Core\McpServer;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\McpServer\Http\Controllers\InternalMcpUsageController;
use DreamFactory\Core\McpServer\Http\Middleware\McpStreamMiddleware;
use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use DreamFactory\Core\McpServer\Models\McpServerConfig;
use DreamFactory\Core\McpServer\Models\SystemMcpServerConfig;
use DreamFactory\Core\McpServer\Services\Mcp;
use DreamFactory\Core\McpServer\Services\SystemMcp;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Route;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    /** Guard against double-registration when this provider is resolved twice. */
    private static bool $middlewareRegistered = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mcp.php', 'mcp');

        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(new ServiceType([
                'name'           => McpServiceTypes::DATA,
                'label'          => 'MCP Server Service',
                'description'    => 'MCP Server service for Model Context Protocol.',
                'group'          => ServiceTypeGroups::MCP,
                'config_handler' => McpServerConfig::class,
                'factory'        => function ($config) {
                    return new Mcp($config);
                },
            ]));

            $df->addType(new ServiceType([
                'name'           => McpServiceTypes::SYSTEM,
                'label'          => 'System API MCP Server',
                'description'    => 'MCP server exposing the DreamFactory System API (services, roles, apps/API keys, admins, environment) so AI clients can administer this instance.',
                'group'          => ServiceTypeGroups::MCP,
                'config_handler' => SystemMcpServerConfig::class,
                'factory'        => function ($config) {
                    return new SystemMcp($config);
                },
            ]));
        });

        // Register internal routes during booting (before normal boot()) so
        // they take priority over df-file's greedy {storage}/{path} catch-all,
        // which has an empty prefix and would otherwise swallow the 2-segment
        // GET /_internal/ai/mcp-usage. Same pattern as df-ai's internal
        // routes.
        $this->app->booting(function (): void {
            $this->registerInternalRoutes();
        });

        $this->registerStreamMiddleware();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/mcp.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mcp');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \DreamFactory\Core\McpServer\Commands\PruneRequestLogs::class,
            ]);
        }
    }

    /**
     * Powers the MCP section of the AI Gateway dashboard. Handler lives in
     * InternalMcpUsageController so it can be unit-tested in isolation.
     */
    private function registerInternalRoutes(): void
    {
        Route::middleware('df.auth_check')->get(
            '_internal/ai/mcp-usage',
            [InternalMcpUsageController::class, 'usage']
        );
    }

    /**
     * Prepend McpStreamMiddleware globally so it can intercept /mcp/* requests
     * before DreamFactory's API routing. Kicked off via $this->app->booting()
     * because the HTTP kernel isn't available during register().
     */
    private function registerStreamMiddleware(): void
    {
        if (self::$middlewareRegistered) {
            return;
        }
        $this->app->booting(function () {
            if (self::$middlewareRegistered) {
                return;
            }
            try {
                $kernel = $this->app->make(Kernel::class);
                if (method_exists($kernel, 'prependMiddleware')) {
                    $kernel->prependMiddleware(McpStreamMiddleware::class);
                    self::$middlewareRegistered = true;
                } elseif (method_exists($kernel, 'pushMiddleware')) {
                    $kernel->pushMiddleware(McpStreamMiddleware::class);
                    self::$middlewareRegistered = true;
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to register MCP stream middleware', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
