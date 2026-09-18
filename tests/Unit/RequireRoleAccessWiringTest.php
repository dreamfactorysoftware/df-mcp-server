<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Utility\RoleAccessGate;
use PHPUnit\Framework\TestCase;

/**
 * "Require role access" switch (#63).
 *
 * Source-wiring assertions that lock the ordering the security argument
 * depends on: the gate runs AFTER the DF session is seeded (so role.services
 * is populated) and BEFORE the catalog is resolved or anything is proxied to
 * a daemon (so a refused identity learns nothing). Plus the upgrade contract:
 * the column defaults to false (existing rows unchanged) and only the model's
 * creating hook flips new rows to true; the migration never touches roles.
 */
class RequireRoleAccessWiringTest extends TestCase
{
    private function controllerSrc(): string
    {
        return file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpStreamController.php');
    }

    public function testGateRunsAfterSessionSeedAndBeforeCatalogOrProxy(): void
    {
        $src = $this->controllerSrc();

        $seedKey = strpos($src, "SessionUtilities::setSessionData(\$auth['app_id'], \$auth['user_id'])");
        $seedOAuth = strpos($src, 'SessionUtilities::setSessionData($appId, $token->user_id)');
        $gate = strpos($src, 'RoleAccessGate::requires($config) && !RoleAccessGate::sessionMayConnect($mcpService)');
        $resolve = strpos($src, 'AvailableServices::resolve($mcpService');
        $proxy = strpos($src, '$client->proxyRequest(');
        $customTools = strpos($src, 'Resolve lookup placeholders in custom tool configs');

        foreach (['seedKey', 'seedOAuth', 'gate', 'resolve', 'proxy', 'customTools'] as $v) {
            $this->assertNotFalse($$v, "$v anchor present");
        }
        $this->assertLessThan($gate, $seedKey, 'key-auth session must be seeded before the gate');
        $this->assertLessThan($gate, $seedOAuth, 'oauth session must be seeded before the gate');
        $this->assertLessThan($customTools, $gate, 'gate must run before custom-tool secrets are resolved');
        $this->assertLessThan($resolve, $gate, 'gate must run before the catalog is resolved');
        $this->assertLessThan($proxy, $gate, 'gate must run before anything reaches a daemon');
        $this->assertSame(1, substr_count($src, 'RoleAccessGate::sessionMayConnect('), 'exactly one gate call site');
    }

    public function testRefusalIsA403JsonRpcErrorAndIsAudited(): void
    {
        $src = $this->controllerSrc();
        $gate = strpos($src, 'RoleAccessGate::sessionMayConnect(');
        $block = substr($src, $gate, 900);

        $this->assertStringContainsString("'denied'", $block, 'refusal is audit-logged with status denied');
        $this->assertStringContainsString('RoleAccessGate::ERROR_CODE', $block);
        $this->assertStringContainsString('], 403)', $block, 'HTTP 403, not 401: the identity is authenticated, just not admitted');
        $this->assertSame(-32003, RoleAccessGate::ERROR_CODE, 'distinct from the -32001 authentication code');
    }

    public function testMigrationDefaultsFalseAndNeverWritesRoles(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/migrations/2026_09_18_000000_add_require_role_access_to_mcp_server_config.php');
        $this->assertStringContainsString("boolean('require_role_access')->default(false)", $src);
        $this->assertStringNotContainsString('role_service_access', $src, 'upgrade must not grant anything');
        $this->assertStringNotContainsString('DB::table', $src);
    }

    public function testNewServicesDefaultToRequiringAccess(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Models/McpServerConfig.php');
        $creating = strpos($src, 'static::creating(');
        $this->assertNotFalse($creating);
        $this->assertStringContainsString(
            'if (is_null($model->require_role_access)) {',
            substr($src, $creating, 1200),
            'creating hook sets the default for new rows only'
        );
        $this->assertStringContainsString("'require_role_access' => 'boolean'", $src);
        $this->assertStringContainsString("case 'require_role_access':", $src, 'exposed in the config schema');
    }

    public function testRequiresParsesStoredValues(): void
    {
        $this->assertFalse(RoleAccessGate::requires(null));
        $this->assertFalse(RoleAccessGate::requires([]));
        $this->assertFalse(RoleAccessGate::requires(['require_role_access' => false]));
        $this->assertFalse(RoleAccessGate::requires(['require_role_access' => '0']));
        $this->assertFalse(RoleAccessGate::requires(['require_role_access' => null]));
        $this->assertTrue(RoleAccessGate::requires(['require_role_access' => true]));
        $this->assertTrue(RoleAccessGate::requires(['require_role_access' => 1]));
        $this->assertTrue(RoleAccessGate::requires(['require_role_access' => '1']));
    }
}
