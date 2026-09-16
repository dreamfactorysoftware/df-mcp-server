<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }

        Schema::table('mcp_server_config', function (Blueprint $table) {
            if (!Schema::hasColumn('mcp_server_config', 'exposed_services')) {
                $table->text('exposed_services')->nullable()->after('disabled_tools');
            }
            if (!Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                $table->boolean('scope_tools')->nullable()->after('exposed_services');
            }
        });

        $this->backfillExistingCatalogs();
    }

    public function down(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }

        Schema::table('mcp_server_config', function (Blueprint $table) {
            if (Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                $table->dropColumn('scope_tools');
            }
            if (Schema::hasColumn('mcp_server_config', 'exposed_services')) {
                $table->dropColumn('exposed_services');
            }
        });
    }

    /**
     * Empty Exposed Services always means "no auto DB/file tools". Snapshot
     * the current database+file service names onto existing MCP rows so their
     * tools/list does not shrink on upgrade. If we cannot resolve that list,
     * fall back to scope_tools=false (legacy instance-wide catalog).
     */
    private function backfillExistingCatalogs(): void
    {
        if (!Schema::hasColumn('mcp_server_config', 'exposed_services')) {
            return;
        }

        $names = $this->currentBackendServiceNames();
        $rows = DB::table('mcp_server_config')->get();
        $touched = [];

        // mcp_server_config is shared with the `system_mcp` service type,
        // whose daemon serves the System API and never auto-mounts DB/file
        // services — exposed_services/scope_tools are meaningless there.
        // Backfill only rows that belong to type-`mcp` services, matching
        // the rowless-service half below.
        if (Schema::hasTable('service')) {
            $mcpServiceIds = [];
            foreach (DB::table('service')->where('type', 'mcp')->pluck('id') as $id) {
                $mcpServiceIds[] = (int) $id;
            }
            $rows = $rows->filter(
                fn ($row) => in_array((int) $row->service_id, $mcpServiceIds, true)
            )->values();
        }

        foreach ($rows as $row) {
            $current = $row->exposed_services ?? null;
            $decoded = is_string($current) ? json_decode($current, true) : $current;
            if (is_array($decoded) && $decoded !== []) {
                continue;
            }

            if ($names !== []) {
                DB::table('mcp_server_config')
                    ->where('service_id', $row->service_id)
                    ->update(['exposed_services' => json_encode(array_values($names))]);
                $touched[] = (int) $row->service_id;
            } elseif (Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                DB::table('mcp_server_config')
                    ->where('service_id', $row->service_id)
                    ->update(['scope_tools' => false]);
                $touched[] = (int) $row->service_id;
            }
        }

        foreach ($this->backfillServicesWithoutConfigRow($names) as $serviceId) {
            $touched[] = $serviceId;
        }

        $this->purgeServiceConfigCache($touched);
    }

    /**
     * A 7.7.0 MCP service created via the API with an empty/omitted config has
     * NO mcp_server_config row at all (df-core's BaseServiceConfigModel skips
     * storeConfig for empty config), yet it served the full instance-wide
     * catalog through the /rpc bridge. Iterating existing rows misses those
     * services, so insert a backfilled row for each of them here.
     *
     * The insert deliberately uses DB::table() (not the Eloquent model): the
     * model's creating() hook generates OAuth credentials and pins app_id as
     * side effects, which a schema migration must not silently trigger. Null
     * OAuth columns exactly mirror the pre-upgrade "no config row" state, and
     * every other column is nullable or defaulted at the schema level.
     *
     * @param string[] $names snapshot of current database+file service names
     * @return int[] service ids that received a backfilled row
     */
    private function backfillServicesWithoutConfigRow(array $names): array
    {
        if (!Schema::hasTable('service')) {
            return [];
        }

        $touched = [];
        $now = date('Y-m-d H:i:s');
        $withRows = DB::table('mcp_server_config')->pluck('service_id')->all();

        $orphans = DB::table('service')
            ->where('type', 'mcp')
            ->whereNotIn('id', $withRows)
            ->get(['id']);

        foreach ($orphans as $service) {
            $row = [
                'service_id' => (int) $service->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($names !== []) {
                $row['exposed_services'] = json_encode(array_values($names));
            } elseif (Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                // Cannot snapshot the backend list: fall back to the legacy
                // instance-wide catalog so the service keeps its 7.7.0 tools.
                $row['scope_tools'] = false;
            }

            DB::table('mcp_server_config')->insert($row);
            $touched[] = (int) $service->id;
        }

        return $touched;
    }

    /**
     * DB::table() writes bypass Eloquent events, so df-core's ServiceManager
     * never hears about the backfill and keeps serving its forever-cached
     * pre-backfill config (Cache::rememberForever('service_mgr:'.$name) in
     * ServiceManager::getDbConfig; the purge is normally event-driven via
     * ServiceManager::purge). Without this, upgraded MCP services see no
     * exposed_services until someone runs cache:clear — i.e. zero DB/file
     * tools. Forget the per-service entries for every touched service plus
     * the service-list/map keys ServiceManager::purge also clears. A cache
     * driver failure must never fail the migration.
     *
     * @param int[] $serviceIds
     */
    private function purgeServiceConfigCache(array $serviceIds): void
    {
        if ($serviceIds === []) {
            return;
        }

        try {
            $names = DB::table('service')
                ->whereIn('id', array_values(array_unique($serviceIds)))
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
            // A broken cache driver (or missing cache table mid-upgrade) must
            // not abort the migration; worst case is the pre-existing
            // stale-cache behavior, cleared by cache:clear.
        }
    }

    /**
     * @return string[]
     */
    private function currentBackendServiceNames(): array
    {
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $sm */
            $sm = app('df.service');
            $names = [];
            foreach (['Database', 'File'] as $group) {
                foreach ($sm->getServiceListByGroup($group, ['name'], true) as $service) {
                    $name = (string) ($service['name'] ?? '');
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }
            }

            return array_values(array_unique($names));
        } catch (\Throwable $e) {
            return [];
        }
    }
};
