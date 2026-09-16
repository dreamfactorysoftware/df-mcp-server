<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Wiring for the two audited exposed_services gotchas (source-level, like
 * ToolListScopingWiringTest — the transforms are unit-tested in
 * ExposedServicesSyncTest and the DB path in ExposedServicesSyncFunctionalTest).
 *
 * 1. Rename/delete drift: exposed_services stores backend NAMES, so the
 *    provider must listen on df-core's Service model — rewrite the name on
 *    rename, remove it on delete — or tools silently vanish / a recreated
 *    service silently inherits exposure.
 * 2. The empty-Exposed-Services save warning must judge the RESULTING stored
 *    row, not the request payload, or every partial write that omits the key
 *    logs a spurious warning.
 */
class ServiceRenameDeleteSyncWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function testProviderBootRegistersServiceRenameAndDeleteListeners(): void
    {
        $src = $this->src('src/ServiceProvider.php');

        $this->assertStringContainsString('registerServiceSyncListeners', $src);
        // Registered from boot(), not register() — model events need the app up.
        $this->assertMatchesRegularExpression(
            '/function boot\(\).*registerServiceSyncListeners\(\)/s',
            $src
        );
        $this->assertStringContainsString('Service::updated(', $src);
        $this->assertStringContainsString('Service::deleted(', $src);
        $this->assertStringContainsString('use DreamFactory\\Core\\Models\\Service;', $src);
    }

    public function testRenameListenerFiresOnlyOnRealNameChanges(): void
    {
        $src = $this->src('src/ServiceProvider.php');

        $this->assertStringContainsString("wasChanged('name')", $src);
        // getOriginal() still holds the pre-save name inside `updated`.
        $this->assertStringContainsString("getOriginal('name')", $src);
        $this->assertStringContainsString('ExposedServicesSync::serviceRenamed(', $src);
        $this->assertStringContainsString('ExposedServicesSync::serviceDeleted(', $src);
    }

    public function testListenerFailuresCanNeverBreakServiceCrud(): void
    {
        $src = $this->src('src/ServiceProvider.php');

        // Both listener closures wrap their bodies in try/catch(\Throwable)
        // and only log — a sync failure must not fail the rename/delete.
        foreach (['Service::updated(', 'Service::deleted('] as $marker) {
            $start = strpos($src, $marker);
            $this->assertNotFalse($start, $marker);
            $body = substr($src, $start, 1200);
            $this->assertStringContainsString('try {', $body, $marker);
            $this->assertStringContainsString('catch (\\Throwable', $body, $marker);
            $this->assertStringContainsString('Log::warning', $body, $marker);
        }

        // And a df-core-less checkout must not fatal at boot.
        $this->assertStringContainsString('class_exists(Service::class)', $src);
    }

    public function testRewriteTargetsOnlyLiveMcpTypeConfigRows(): void
    {
        $src = $this->src('src/Support/ExposedServicesSync.php');

        // system_mcp strips exposed_services; nothing but type=`mcp` may be
        // rewritten, and soft-deleted config rows stay untouched.
        $this->assertStringContainsString("->where('service.type', 'mcp')", $src);
        $this->assertStringContainsString("whereNull('mcp_server_config.deleted_at')", $src);
        $this->assertStringContainsString("DB::table('mcp_server_config')", $src);
        $this->assertStringContainsString('json_encode(array_values(', $src);
        // Tolerant decoding shared with the daemon catalog.
        $this->assertStringContainsString('AvailableServices::names(', $src);
        // Query-builder writes only — an Eloquent save here would fire config
        // model hooks from inside a Service event.
        $this->assertStringNotContainsString('McpServerConfig::', $src);
        $this->assertStringNotContainsString('->save(', $src);
    }

    public function testRewritePurgesTheForeverConfigCacheThroughTheSharedHelper(): void
    {
        $sync = $this->src('src/Support/ExposedServicesSync.php');
        $this->assertStringContainsString('ServiceCache::purgeConfig(', $sync);

        // Exactly the purge the 2026_09_02 migration performs (extracted so
        // both call the same code): per-service entries plus the map keys.
        $helper = $this->src('src/Support/ServiceCache.php');
        $this->assertStringContainsString("Cache::forget('service_mgr:' . \$name)", $helper);
        $this->assertStringContainsString("'service_mgr:name_type_map'", $helper);

        $migration = $this->src(
            'database/migrations/2026_09_02_000000_add_exposed_services_to_mcp_server_config.php'
        );
        $this->assertStringContainsString('ServiceCache::purgeConfig(', $migration);
    }

    public function testEmptyExposedWarningJudgesTheStoredRowNotThePayload(): void
    {
        $src = $this->src('src/Models/McpServerConfig.php');

        $start = strpos($src, 'protected static function warnIfEmptyExposed(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 2200);

        // Consults the resulting stored state after the write...
        $this->assertStringContainsString('whereServiceId($id)->first()', $body);
        $this->assertStringContainsString('$stored->exposed_services', $body);
        // ...and stays quiet when scoping would not even apply.
        $this->assertStringContainsString('AvailableServices::scopingApplies(', $body);
        $this->assertStringContainsString("config('mcp.scope_tools', true)", $body);
        // The genuine empty-save warning survives.
        $this->assertStringContainsString('no Exposed Services selected', $body);
        // And a broken table mid-migration cannot break saves.
        $this->assertStringContainsString('catch (\\Throwable', $body);

        // SystemMcpServerConfig keeps suppressing it via late static binding.
        $system = $this->src('src/Models/SystemMcpServerConfig.php');
        $this->assertStringContainsString('protected static function warnIfEmptyExposed(', $system);
        $this->assertStringContainsString('Intentionally empty', $system);
    }
}
