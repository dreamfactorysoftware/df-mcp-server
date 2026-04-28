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

        // Register the internal Gateway-dashboard endpoint at register() time
        // so it lands in the route collection BEFORE df-file's boot()
        // registers the catchall `{storage}/{path}` route — otherwise the
        // catchall captures `/_internal/ai/mcp-usage` and we get a 404.
        $this->registerInternalRoutes();

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

        // Load MCP routes (these are not affected — middleware handles /mcp/*)
        $this->loadRoutesFrom(__DIR__ . '/../routes/mcp.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'mcp');
    }

    /**
     * Register the /_internal/ai/mcp-usage admin endpoint that powers the
     * Gateway dashboard's MCP section. Registered here (not in the routes
     * file) to match df-ai's pattern — direct boot() registration runs
     * earlier in the dispatch chain than loadRoutesFrom and avoids being
     * captured by Laravel's web middleware group.
     */
    private function registerInternalRoutes(): void
    {
        \Illuminate\Support\Facades\Route::middleware('df.auth_check')->get(
            '_internal/ai/mcp-usage',
            function (\Illuminate\Http\Request $request) {
                if (!\DreamFactory\Core\Utility\Session::isSysAdmin()) {
                    return response()->json(
                        ['error' => ['message' => 'Admin access required.']],
                        403
                    );
                }

                $period = $request->get('period', '7d');
                $since = \DreamFactory\Core\McpServer\Utility\McpUsageAggregator::parsePeriod($period);
                $driver = \DB::connection()->getDriverName();

                $filters = [];
                foreach (\DreamFactory\Core\McpServer\Utility\McpUsageAggregator::FILTER_KEYS as $key) {
                    if (!$request->has($key)) {
                        continue;
                    }
                    $raw = $request->get($key);
                    $values = is_array($raw)
                        ? $raw
                        : array_filter(array_map('trim', explode(',', (string) $raw)), fn($v) => $v !== '');
                    if (!empty($values)) {
                        $filters[$key] = array_values($values);
                    }
                }

                $result = \DreamFactory\Core\McpServer\Utility\McpUsageAggregator::aggregate($since, $driver, $filters);
                $result['period'] = $period;
                return response()->json($result);
            }
        );
    }
}

