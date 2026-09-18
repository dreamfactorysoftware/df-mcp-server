<?php

namespace DreamFactory\Core\McpServer;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\McpServer\Http\Controllers\InternalMcpAccessController;
use DreamFactory\Core\McpServer\Http\Controllers\InternalMcpHealthController;
use DreamFactory\Core\McpServer\Http\Controllers\InternalMcpUsageController;
use DreamFactory\Core\McpServer\Http\Middleware\McpStreamMiddleware;
use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use DreamFactory\Core\McpServer\Models\McpServerConfig;
use DreamFactory\Core\McpServer\Models\SystemMcpServerConfig;
use DreamFactory\Core\McpServer\Services\Mcp;
use DreamFactory\Core\McpServer\Services\SystemMcp;
use DreamFactory\Core\McpServer\Support\ExposedServicesSync;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use Illuminate\Console\Scheduling\Schedule;
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
            $this->scheduleRequestLogPrune();
        }

        $this->registerServiceSyncListeners();
    }

    /**
     * mcp_server_config.exposed_services stores backend service NAMES, which
     * df-core does not track: without these listeners, renaming a backend
     * silently drops it from every MCP endpoint's tools/list, and deleting
     * one leaves the stale name behind so a service recreated under it
     * silently inherits the exposure (name-squatting). Rewrites are handled
     * by ExposedServicesSync; a listener failure must never break service
     * CRUD, hence the Throwable guards.
     */
    private function registerServiceSyncListeners(): void
    {
        if (!class_exists(Service::class)) {
            // Package checkout without df-core (e.g. static analysis).
            return;
        }

        Service::updated(function (Service $service): void {
            try {
                if (!$service->wasChanged('name')) {
                    return;
                }
                // Inside the `updated` event getOriginal() still returns the
                // pre-save attributes (syncOriginal runs later, in finishSave).
                $oldName = (string) $service->getOriginal('name');
                $newName = (string) $service->getAttribute('name');
                if ($oldName === '' || $newName === '' || $oldName === $newName) {
                    return;
                }
                ExposedServicesSync::serviceRenamed($oldName, $newName);
            } catch (\Throwable $e) {
                \Log::warning('Failed to sync MCP exposed_services after a service rename', [
                    'service_id' => $service->getKey(),
                    'error'      => $e->getMessage(),
                ]);
            }
        });

        Service::deleted(function (Service $service): void {
            try {
                // Fires for soft deletes too — treat the service as gone
                // either way; a restore is a rename-free re-expose decision
                // the admin makes explicitly.
                $name = (string) $service->getAttribute('name');
                if ($name === '') {
                    return;
                }
                ExposedServicesSync::serviceDeleted($name);
            } catch (\Throwable $e) {
                \Log::warning('Failed to sync MCP exposed_services after a service delete', [
                    'service_id' => $service->getKey(),
                    'error'      => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Run mcp:prune-request-logs daily while audit logging is on and a
     * retention window is set, so mcp_request_log stays bounded without the
     * customer wiring up the schedule. Hooked on Schedule resolution, so it
     * only does anything under schedule:run / schedule:list.
     *
     * Deliberately no withoutOverlapping()/onOneServer(): both take a lock
     * through the cache store, and where that store is unreachable (e.g. the
     * redis driver with REDIS_HOST unset) schedule:run silently skips the
     * task. An overlapping or per-node duplicate run just finds nothing left
     * to delete.
     */
    private function scheduleRequestLogPrune(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (config('mcp.audit_logging.enabled', true)
                && (int) config('mcp.audit_logging.retention_days', 90) > 0) {
                $schedule->command('mcp:prune-request-logs')->daily();
            }
        });
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
        // "Who can connect" for one MCP service: granted roles + roles seen in the log.
        Route::middleware('df.auth_check')->get(
            '_internal/ai/mcp-access',
            [InternalMcpAccessController::class, 'access']
        );
        // Daemon + install sanity report for the System page and the MCP
        // service status chip. Admin-only (checked in the controller).
        Route::middleware('df.auth_check')->get(
            '_internal/ai/mcp-health',
            [InternalMcpHealthController::class, 'health']
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
