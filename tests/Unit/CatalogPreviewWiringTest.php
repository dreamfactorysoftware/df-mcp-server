<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Catalog preview (issue #64) source wiring:
 *  - GET /_internal/ai/mcp-catalog is registered under df.auth_check and
 *    admin-gated before anything else runs,
 *  - the preview resolves the backend catalog through the SAME
 *    AvailableServices::resolve() the proxy uses, inside a role-seeded
 *    session that is restored afterwards,
 *  - the daemon is asked for a preview (no _mcpPayload) — never a real MCP
 *    request, and no audit row is written,
 *  - the daemon computes the list through the real registration path.
 */
class CatalogPreviewWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    public function testRouteIsRegisteredUnderAuthCheck(): void
    {
        $s = $this->src('src/ServiceProvider.php');
        $this->assertMatchesRegularExpression(
            "/Route::middleware\\('df\\.auth_check'\\)->get\\(\\s*'_internal\\/ai\\/mcp-catalog',\\s*\\[InternalMcpCatalogController::class, 'preview'\\]/s",
            $s
        );
    }

    public function testPreviewIsAdminGatedAndSharesTheCatalogResolver(): void
    {
        $s = $this->src('src/Http/Controllers/InternalMcpCatalogController.php');

        $gate = strpos($s, 'if (!Session::isSysAdmin())');
        $body = strpos($s, 'public function preview(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($body);
        $this->assertLessThan(strpos($s, '$request->get(', $body), $gate, 'admin gate runs before any input is read');

        // One catalog path, the shared one, evaluated as the previewed role.
        $this->assertSame(1, substr_count($s, 'AvailableServices::resolve('));
        $run = strpos($s, 'RoleSession::run(');
        $this->assertNotFalse($run);
        $this->assertGreaterThan($run, strpos($s, 'AvailableServices::resolve('), 'resolve() runs inside the role session');
        $this->assertGreaterThan($run, strpos($s, 'Session::getServicePermissions('), 'verb masks are read as the role');
        $this->assertStringContainsString('VerbsMask::maskToArray(', $s);
        $this->assertStringContainsString('Role::getCachedInfo($roleId)', $s);
        $this->assertStringContainsString("App::getCachedInfo(\$appId, 'role_id')", $s);
        $this->assertStringNotContainsString('getServiceListByGroup', $s);

        // Daemon routing and gating as the proxy does it; type-gated to the data plane.
        $this->assertStringContainsString('DaemonTarget::forServiceType($service->getType())', $s);
        $this->assertStringContainsString("if (!\$target['enabled'])", $s);
        $this->assertStringContainsString('!== McpServiceTypes::DATA', $s);

        // Preview, never a real MCP exchange, never an audit row.
        $this->assertStringContainsString('->catalogPreview(', $s);
        $this->assertStringNotContainsString('proxyRequest(', $s);
        $this->assertStringNotContainsString('rpcStateless(', $s);
        $this->assertStringNotContainsString('RequestLogger', $s);
        $this->assertStringNotContainsString('_mcpPayload', $s);
    }

    public function testDaemonClientPreviewCarriesTheEnvelopeConfigButNoPayload(): void
    {
        $s = $this->src('src/Client/McpDaemonClient.php');
        $start = strpos($s, 'public function catalogPreview(');
        $this->assertNotFalse($start);
        $end = strpos($s, 'private static function internalKeyHeader(');
        $method = substr($s, $start, $end - $start);

        $this->assertStringContainsString("'/mcp/catalog/preview'", $method);
        $this->assertStringContainsString("'_mcpConfig'", $method);
        $this->assertStringContainsString("'_mcpAvailableServices' => array_values(\$availableServices)", $method);
        $this->assertStringContainsString('self::internalKeyHeader()', $method);
        $this->assertStringNotContainsString('_mcpPayload', $method);
        $this->assertStringNotContainsString('X-DreamFactory-Session-Token', $method);
        $this->assertStringNotContainsString('X-DreamFactory-API-Key', $method);
    }

    public function testRoleSessionRestoresTheCallerEvenOnFailure(): void
    {
        $s = $this->src('src/Support/RoleSession.php');
        $this->assertStringContainsString("\\Session::forget('user');", $s);
        $this->assertStringContainsString('Session::setRoleInfo($roleInfo);', $s);
        $this->assertMatchesRegularExpression(
            '/\} finally \{\s*\\\\Session::flush\(\);\s*\\\\Session::put\(\$snapshot\);/s',
            $s
        );
    }

    public function testDaemonPreviewRouteIsKeyGatedAndUsesTheRealRegistrationPath(): void
    {
        $server = $this->src('daemon/src/server.ts');
        $route = strpos($server, "app.post('/mcp/catalog/preview'");
        $this->assertNotFalse($route);
        $gate = strpos($server, "req.headers['x-mcp-internal-key'] !== INTERNAL_API_KEY", $route);
        $call = strpos($server, 'previewCatalog(', $route);
        $this->assertNotFalse($gate);
        $this->assertNotFalse($call);
        $this->assertLessThan($call, $gate, 'internal key is checked before the preview runs');

        // The proxied request and the preview parse the config through one function.
        $this->assertStringContainsString('parseMcpConfig(mcpConfigData)', $server);

        $svc = $this->src('daemon/src/services/catalog-preview.service.ts');
        $this->assertStringContainsString('createServer(', $svc);
        $this->assertStringContainsString('parseMcpConfig(', $svc);
        $this->assertStringContainsString('parseAvailableServicesList(', $svc);
        $this->assertStringContainsString('client.listTools()', $svc);
        $this->assertStringNotContainsString('DreamFactoryService', $svc, 'no DreamFactory calls');
        $this->assertStringNotContainsString('callTool(', $svc, 'nothing is executed');
    }
}
