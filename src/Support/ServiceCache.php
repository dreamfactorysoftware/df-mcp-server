<?php

namespace DreamFactory\Core\McpServer\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * df-core's ServiceManager caches each service's stored config forever
 * (Cache::rememberForever('service_mgr:'.$name) in getDbConfig) and purges it
 * only through Eloquent model events (ServiceManager::purge). Anything that
 * edits mcp_server_config via the query builder bypasses those events, so it
 * must forget the per-service entries and the service-list/map keys itself —
 * otherwise MCP endpoints keep serving the stale pre-edit config until
 * someone runs cache:clear.
 *
 * Shared by the 2026_09_02 exposed_services upgrade backfill and the
 * service rename/delete listeners in ServiceProvider (ExposedServicesSync).
 */
class ServiceCache
{
    /**
     * Forget the forever-cached config entry for each service id, plus the
     * four service map keys ServiceManager::purge also clears. A cache-driver
     * failure must never break the caller (schema migration or service CRUD);
     * worst case is the pre-existing stale-cache behavior, cleared by
     * cache:clear.
     *
     * @param int[] $serviceIds
     */
    public static function purgeConfig(array $serviceIds): void
    {
        if ($serviceIds === []) {
            return;
        }

        try {
            $names = DB::table('service')
                ->whereIn('id', array_values(array_unique(array_map('intval', $serviceIds))))
                ->pluck('name');

            foreach ($names as $name) {
                Cache::forget('service_mgr:' . $name);
            }

            foreach ([
                'service_mgr:id_name_map_active',
                'service_mgr:id_name_map',
                'service_mgr:name_type_map_active',
                'service_mgr:name_type_map',
            ] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            // Intentionally swallowed — see the docblock.
        }
    }
}
