<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level wiring checks for the per-type daemon routing:
 *  - middleware publishes `mcp_service_type` on the request,
 *  - the stream controller and the /rpc bridge pick the daemon via DaemonTarget,
 *  - McpDaemonClient sends X-Mcp-Internal-Key on both proxy paths when configured,
 *  - config/mcp.php declares the system_daemon section and internal_key.
 */
class DaemonRoutingWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    public function testMiddlewareSetsServiceTypeAttribute(): void
    {
        $s = $this->src('src/Http/Middleware/McpStreamMiddleware.php');
        $this->assertStringContainsString("\$request->attributes->set('mcp_service_type', \$serviceType);", $s);
        $this->assertStringContainsString("method_exists(\$service, 'getType')", $s);
    }

    public function testStreamControllerUsesDaemonTarget(): void
    {
        $s = $this->src('src/Http/Controllers/McpStreamController.php');
        $this->assertStringContainsString("\$request->attributes->get('mcp_service_type')", $s);
        $this->assertStringContainsString('DaemonTarget::forServiceType(', $s);
        $this->assertStringContainsString("if (!\$target['enabled'])", $s);
        $this->assertStringContainsString("new McpDaemonClient(\$target['url'])", $s);
        $this->assertStringContainsString("McpServiceTypes::isSystem(\$target['type'])", $s);
        $this->assertStringNotContainsString("config('mcp.daemon.enabled'", $s);
    }

    public function testRpcBridgeUsesDaemonClientHookAndEnabledGate(): void
    {
        $s = $this->src('src/Services/Mcp.php');
        $this->assertStringContainsString('protected function daemonClient(): McpDaemonClient', $s);
        $this->assertStringContainsString('$this->daemonClient()->rpcStateless(', $s);
        $this->assertStringContainsString("DaemonTarget::forServiceType(\$this->getType())", $s);
        $this->assertStringContainsString("if (!\$target['enabled'])", $s);
        $this->assertStringNotContainsString('(new McpDaemonClient())', $s);

        $sys = $this->src('src/Services/SystemMcp.php');
        $this->assertStringContainsString('class SystemMcp extends Mcp', $sys);
        $this->assertMatchesRegularExpression(
            '/function resolveAvailableServices\(\): array\s*\{\s*return \[\];/s',
            $sys
        );
    }

    public function testDaemonClientSendsInternalKeyOnBothPaths(): void
    {
        $s = $this->src('src/Client/McpDaemonClient.php');
        $this->assertSame(2, substr_count($s, '$headers += self::internalKeyHeader();'));
        $this->assertStringContainsString("config('mcp.daemon.internal_key')", $s);
        $this->assertStringContainsString("'X-Mcp-Internal-Key' => \$key", $s);
        // ConnectException message names the URL it tried.
        $this->assertStringContainsString("'MCP daemon is not reachable at ' . \$this->daemonUrl", $s);
    }

    public function testConfigDeclaresSystemDaemonAndInternalKey(): void
    {
        $config = require __DIR__ . '/../../config/mcp.php';
        $this->assertArrayHasKey('system_daemon', $config);
        $this->assertArrayHasKey('enabled', $config['system_daemon']);
        $this->assertArrayHasKey('url', $config['system_daemon']);
        $this->assertArrayHasKey('internal_key', $config['daemon']);

        $raw = $this->src('config/mcp.php');
        $this->assertStringContainsString("env('MCP_SYSTEM_DAEMON_ENABLED', true)", $raw);
        $this->assertStringContainsString("env('MCP_SYSTEM_DAEMON_URL', 'http://127.0.0.1:3700')", $raw);
        $this->assertStringContainsString("env('MCP_INTERNAL_KEY')", $raw);
    }
}
