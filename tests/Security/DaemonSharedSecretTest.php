<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: when MCP_INTERNAL_KEY is configured, the daemon must require
 * the matching `x-mcp-internal-key` header on the MCP service endpoint
 * (/mcp/:serviceName), not only on the cache-clear endpoint.
 *
 * The April 2026 audit (df-mcp-server F-03) found that the daemon listens
 * on 127.0.0.1:8006 and only verifies the caller-supplied
 * `x-dreamfactory-session-token` header. Any local process or container
 * sharing the host's loopback can bypass the PHP RBAC layer by speaking
 * directly to the daemon with a valid session token (which can be obtained
 * via a normal user login).
 *
 * The fix re-uses the existing MCP_INTERNAL_KEY env var (already gating
 * /mcp/cache/clear) on the main /mcp/:serviceName endpoint when it is set.
 *
 * Tested at the TypeScript-source level since the daemon has no test
 * framework configured.
 */
class DaemonSharedSecretTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../daemon/src/server.ts';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testMcpEndpointChecksInternalKeyWhenConfigured(): void
    {
        // Find the /mcp/:serviceName route handler.
        $routeStart = strpos($this->contents, "app.all('/mcp/:serviceName'");
        $this->assertNotFalse($routeStart, 'MCP service route handler must exist');

        // Slice the next ~3KB which covers the auth-check prelude.
        $prelude = substr($this->contents, $routeStart, 3000);

        $this->assertMatchesRegularExpression(
            '/INTERNAL_API_KEY/',
            $prelude,
            'MCP service route must reference INTERNAL_API_KEY for shared-secret auth'
        );
        $this->assertMatchesRegularExpression(
            "/x-mcp-internal-key/i",
            $prelude,
            'MCP service route must check the x-mcp-internal-key header'
        );
    }

    public function testMcpEndpointRejectsWith403WhenKeyMismatches(): void
    {
        $routeStart = strpos($this->contents, "app.all('/mcp/:serviceName'");
        $prelude = substr($this->contents, $routeStart, 3000);

        // We accept any 403 status response that mentions the internal key
        // (matches the cache-clear endpoint pattern).
        $this->assertMatchesRegularExpression(
            '/\.status\s*\(\s*403\s*\)/',
            $prelude,
            'MCP service route must return 403 on internal-key mismatch'
        );
    }
}
