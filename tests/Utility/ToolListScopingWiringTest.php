<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use PHPUnit\Framework\TestCase;

/**
 * Wiring + daemon-side token-cost guards for issue #48.
 *
 * The PHP catalog filter is unit-tested in AvailableServicesTest. These
 * assertions lock the call sites and the daemon's "don't emit duplicate
 * cross-service tools / essays" behavior, which this package tests at the
 * source level (no daemon test runner).
 */
class ToolListScopingWiringTest extends TestCase
{
    public function testControllerResolvesAvailableServicesThroughTheSharedHelper(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpStreamController.php');

        $this->assertStringContainsString('AvailableServices::resolve($mcpService', $src);
        $this->assertStringNotContainsString('function getAvailableServices(', $src);
        $this->assertStringNotContainsString('getServiceListByGroup', $src);
    }

    public function testRpcBridgeResolvesAvailableServicesThroughTheSharedHelper(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Services/Mcp.php');

        $this->assertStringContainsString('AvailableServices::resolve(', $src);
        // The hook method survives as SystemMcp's override point (it returns []
        // there — the system daemon never auto-mounts DB/file services), but it
        // must stay a pure delegation to the shared helper: no duplicated
        // catalog/role logic in the bridge.
        $this->assertMatchesRegularExpression(
            '/function resolveAvailableServices\(\): array\s*\{\s*\$config = \$this->getConfig\(\);\s*'
            . 'return AvailableServices::resolve\(\$this->name, is_array\(\$config\) \? \$config : \[\]\);\s*\}/s',
            $src
        );
        $this->assertStringNotContainsString('getServiceListByGroup', $src);
        $this->assertStringNotContainsString("get('role.services')", $src);
    }

    public function testUpgradeBackfillsExistingRowsWithCurrentBackends(): void
    {
        $src = file_get_contents(
            __DIR__ . '/../../database/migrations/2026_09_02_000000_add_exposed_services_to_mcp_server_config.php'
        );

        $this->assertStringContainsString('backfillExistingCatalogs', $src);
        $this->assertStringContainsString('currentBackendServiceNames', $src);
        $this->assertStringContainsString("update(['exposed_services'", $src);
        $this->assertStringContainsString("['scope_tools' => false]", $src);
    }

    /**
     * The backfill writes via DB::table(), which bypasses Eloquent events, so
     * df-core's ServiceManager would keep serving its forever-cached
     * pre-backfill config (Cache::rememberForever('service_mgr:'.$name))
     * until cache:clear. The migration must forget the per-service entries
     * and the service map keys itself, and a cache-driver failure must never
     * fail the migration. The purge lives in the shared ServiceCache helper
     * so the ServiceProvider's rename/delete listeners run the exact same
     * one; the migration must delegate to it, not fork a private copy.
     */
    public function testUpgradeBackfillPurgesTheForeverServiceConfigCache(): void
    {
        $src = file_get_contents(
            __DIR__ . '/../../database/migrations/2026_09_02_000000_add_exposed_services_to_mcp_server_config.php'
        );

        $this->assertStringContainsString('purgeServiceConfigCache', $src);
        $this->assertStringContainsString('ServiceCache::purgeConfig(', $src);
        // No private fork of the purge left behind in the migration.
        $this->assertStringNotContainsString('Cache::forget', $src);

        $helper = file_get_contents(__DIR__ . '/../../src/Support/ServiceCache.php');

        $this->assertStringContainsString("Cache::forget('service_mgr:' . \$name)", $helper);
        $this->assertStringContainsString("'service_mgr:id_name_map_active'", $helper);
        $this->assertStringContainsString("'service_mgr:id_name_map'", $helper);
        $this->assertStringContainsString("'service_mgr:name_type_map_active'", $helper);
        $this->assertStringContainsString("'service_mgr:name_type_map'", $helper);

        // The purge is wrapped so cache-driver trouble cannot abort the
        // caller (upgrade migration or service CRUD).
        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*Cache::forget/s',
            $helper
        );
        $this->assertStringContainsString('catch (\\Throwable', $helper);
    }

    /**
     * A 7.7.0 MCP service created via API with empty/omitted config has NO
     * mcp_server_config row (df-core skips storeConfig for empty config) but
     * still served the instance-wide catalog through /rpc. The migration must
     * backfill a row for those services too — via DB::table() inserts, never
     * the Eloquent model (whose creating() hook generates credentials).
     */
    public function testUpgradeBackfillsMcpServicesThatHaveNoConfigRow(): void
    {
        $src = file_get_contents(
            __DIR__ . '/../../database/migrations/2026_09_02_000000_add_exposed_services_to_mcp_server_config.php'
        );

        $this->assertStringContainsString('backfillServicesWithoutConfigRow', $src);
        $this->assertStringContainsString("->where('type', 'mcp')", $src);
        $this->assertStringContainsString("->whereNotIn('id', \$withRows)", $src);
        $this->assertStringContainsString("DB::table('mcp_server_config')->insert(", $src);
        // No Eloquent writes anywhere in the migration.
        $this->assertStringNotContainsString('McpServerConfig::', $src);
        $this->assertStringNotContainsString('->save(', $src);
    }

    public function testEmptyExposedServicesWarnsOnSave(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Models/McpServerConfig.php');

        $this->assertStringContainsString('warnIfEmptyExposed', $src);
        $this->assertStringContainsString('no Exposed Services selected', $src);
        $this->assertStringContainsString('Empty always means none', $src);
    }

    public function testResolveFallsBackToScopedByDefault(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Utility/AvailableServices.php');

        $this->assertStringContainsString("config('mcp.scope_tools', true)", $src);
        $this->assertDoesNotMatchRegularExpression(
            '/function scope\(\s*array \$services,\s*string \$mcpServiceName/',
            $src
        );
    }

    public function testExposedServicesIsAMultiPicklistInTheAdminSchema(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Models/McpServerConfig.php');

        $this->assertStringContainsString("'multi_picklist'", $src);
        $this->assertStringContainsString('backendServiceChoices', $src);
        $this->assertStringContainsString('ServiceTypeGroups::DATABASE', $src);
        $this->assertStringContainsString('ServiceTypeGroups::FILE', $src);
    }

    public function testConfigExposesScopeToolsEnvFlag(): void
    {
        $src = file_get_contents(__DIR__ . '/../../config/mcp.php');

        $this->assertStringContainsString("'scope_tools'", $src);
        $this->assertStringContainsString('MCP_SCOPE_TOOLS', $src);
        $this->assertStringContainsString("env('MCP_SCOPE_TOOLS', true)", $src);
        $this->assertStringContainsString('FILTER_VALIDATE_BOOLEAN', $src);
    }

    public function testCrossServiceDbToolsAreSkippedForASingleDatabase(): void
    {
        $src = file_get_contents(__DIR__ . '/../../daemon/src/services/api-connector.tools.ts');

        $this->assertMatchesRegularExpression(
            '/dbConfigs\.length\s*<\s*2/',
            $src,
            'all_* database tools must not register when only one DB is in catalog'
        );
        $this->assertStringContainsString("'all_get_tables'", $src);
    }

    public function testCrossServiceFileToolsAreSkippedForASingleFileService(): void
    {
        $src = file_get_contents(__DIR__ . '/../../daemon/src/services/file-api.tools.ts');

        $this->assertMatchesRegularExpression(
            '/fileConfigs\.length\s*<\s*2/',
            $src,
            'all_list_files must not register when only one file service is in catalog'
        );
    }

    public function testEmptyPhpCatalogIsAuthoritativeInTheDaemon(): void
    {
        $src = file_get_contents(__DIR__ . '/../../daemon/src/utils/utils.ts');

        $this->assertStringContainsString('Array.isArray(availableServicesFromBody)', $src);
        $this->assertStringContainsString('if (parsed !== null)', $src);
        $this->assertStringContainsString('Empty array is authoritative', $src);
        $this->assertStringNotContainsString(
            'availableServicesFromBody.length > 0',
            $src
        );
    }

    public function testProxyAlwaysSendsAvailableServicesHeader(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Client/McpDaemonClient.php');

        $this->assertStringContainsString(
            "\$headers['X-Mcp-Available-Services'] = json_encode(array_values(\$availableServices))",
            $src
        );
        $this->assertStringNotContainsString(
            'if (!empty($availableServices))',
            $src
        );
    }

    public function testGetTableDataDescriptionIsCompact(): void
    {
        $src = file_get_contents(__DIR__ . '/../../daemon/src/services/tools.service.ts');

        $this->assertStringNotContainsString('STOP — AGGREGATION', $src);
        $this->assertStringNotContainsString('Filter syntax: field=value', $src);
        $this->assertStringContainsString('use aggregate_data', $src);
    }
}
