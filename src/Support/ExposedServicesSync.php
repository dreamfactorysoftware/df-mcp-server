<?php

namespace DreamFactory\Core\McpServer\Support;

use DreamFactory\Core\McpServer\Utility\AvailableServices;
use Illuminate\Support\Facades\DB;

/**
 * Keeps mcp_server_config.exposed_services in step with service renames and
 * deletes. The column stores backend service NAMES, and df-core knows nothing
 * about it: without this sync, renaming a backend silently drops it from
 * every MCP endpoint's tools/list (the stale old name matches nothing), and
 * deleting one leaves its name behind — so a service later recreated under
 * the same name silently inherits the exposure (name-squatting).
 *
 * Wired to df-core's Service model events in ServiceProvider::boot(). Only
 * rows belonging to type-`mcp` services are rewritten: `system_mcp` strips
 * exposed_services from its config entirely, and no other type reads it.
 *
 * Writes go through the query builder on purpose (an Eloquent save here
 * would fire config-model hooks from inside a Service event). That bypasses
 * ServiceManager's event-driven cache purge, so every rewritten service's
 * forever-cached config is forgotten via ServiceCache::purgeConfig — the
 * same helper the 2026_09_02 upgrade backfill uses.
 */
class ExposedServicesSync
{
    /**
     * A backend service was renamed: rewrite the old name to the new one in
     * every MCP endpoint's exposed_services list.
     */
    public static function serviceRenamed(string $oldName, string $newName): void
    {
        self::rewrite(
            static fn (array $names) => self::renameIn($names, $oldName, $newName)
        );
    }

    /**
     * A backend service is gone (hard- or soft-deleted — either way it can no
     * longer serve tools): remove its name from every exposed_services list.
     */
    public static function serviceDeleted(string $name): void
    {
        self::rewrite(
            static fn (array $names) => self::removeFrom($names, $name)
        );
    }

    /**
     * Replace every case-insensitive match of $old with $new, then dedupe.
     * Pure — safe to unit-test standalone.
     *
     * @param  string[]  $names
     * @return string[]|null  null when nothing matched (caller skips the write)
     */
    public static function renameIn(array $names, string $old, string $new): ?array
    {
        $changed = false;
        $result = [];
        foreach ($names as $name) {
            if (strcasecmp($name, $old) === 0) {
                $changed = true;
                $name = $new;
            }
            $result[] = $name;
        }

        return $changed ? array_values(array_unique($result)) : null;
    }

    /**
     * Remove every case-insensitive match of $name. An emptied list stays an
     * empty array — "expose nothing" — never a fallback to the full catalog.
     * Pure — safe to unit-test standalone.
     *
     * @param  string[]  $names
     * @return string[]|null  null when nothing matched (caller skips the write)
     */
    public static function removeFrom(array $names, string $name): ?array
    {
        $result = array_values(array_filter(
            $names,
            static fn ($n) => strcasecmp($n, $name) !== 0
        ));

        return count($result) === count($names) ? null : $result;
    }

    /**
     * Apply $transform to every live type-`mcp` config row's decoded
     * exposed_services list, persist the rows it changes, and purge their
     * services' config caches.
     *
     * @param callable(string[]): ?array $transform returns the new list, or
     *                                              null to leave the row alone
     */
    private static function rewrite(callable $transform): void
    {
        $rows = DB::table('mcp_server_config')
            ->join('service', 'service.id', '=', 'mcp_server_config.service_id')
            ->where('service.type', 'mcp')
            ->whereNull('mcp_server_config.deleted_at')
            ->whereNotNull('mcp_server_config.exposed_services')
            ->get(['mcp_server_config.service_id', 'mcp_server_config.exposed_services']);

        $touched = [];
        foreach ($rows as $row) {
            // Same tolerant decoding the daemon catalog uses (JSON, CSV,
            // arrays of names or of {name: ...} objects).
            $names = AvailableServices::names($row->exposed_services);
            if ($names === []) {
                continue;
            }

            $updated = $transform($names);
            if ($updated === null) {
                continue;
            }

            DB::table('mcp_server_config')
                ->where('service_id', $row->service_id)
                ->whereNull('deleted_at')
                ->update(['exposed_services' => json_encode(array_values($updated))]);
            $touched[] = (int) $row->service_id;
        }

        ServiceCache::purgeConfig($touched);
    }
}
