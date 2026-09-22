<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: the daemon requires the shared secret on every /mcp route and fails
 * closed. The April 2026 audit (df-mcp-server F-03) found that any local process
 * could call the daemon on 127.0.0.1 with a valid session token and bypass the
 * PHP RBAC layer. The check used to run only when MCP_INTERNAL_KEY was set, and
 * the PHP proxy did not send the header by default; now PHP generates a key when
 * none is configured and the daemon rejects any request without it.
 *
 * Behaviour is covered by daemon/src/services/internal-key.test.ts (real daemon
 * on an ephemeral port); these assertions pin the wiring at the source level.
 */
class DaemonSharedSecretTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function testGateIsMountedOnEveryMcpRouteBeforeAnyHandler(): void
    {
        $server = $this->src('daemon/src/server.ts');
        $gate = strpos($server, "app.use('/mcp', internalKeyGate);");
        $this->assertNotFalse($gate, 'every /mcp route must be internal-key gated');
        foreach (["app.post('/mcp/cache/clear'", "app.post('/mcp/catalog/preview'", "app.all('/mcp/:serviceName'"] as $route) {
            $at = strpos($server, $route);
            $this->assertNotFalse($at, $route);
            $this->assertLessThan($at, $gate, "{$route} registered after the gate");
        }
        // The old optional gate ("only when MCP_INTERNAL_KEY is set") is gone.
        $this->assertStringNotContainsString('INTERNAL_API_KEY &&', $server);
    }

    public function testGateFailsClosedWithAConstantTimeCompare(): void
    {
        $key = $this->src('daemon/src/services/internal-key.ts');
        $this->assertStringContainsString('timingSafeEqual(a, b)', $key);
        $this->assertStringContainsString("expected === ''", $key, 'an empty expected key never matches');
        $this->assertMatchesRegularExpression('/\.status\(\s*403\s*\)/', $key);
        $this->assertStringContainsString("'storage', 'framework', 'mcp_internal_key'", $key);
    }
}
