<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use PHPUnit\Framework\TestCase;

/**
 * Security: /mcp/{service} is served only for MCP endpoint service types.
 *
 * McpStreamMiddleware used to resolve any service name and hand it to the
 * stream controller as a data MCP endpoint, so /mcp/db answered tools/list.
 * Those services have no oauth_client_id, so the per-service bearer binding
 * was skipped and a token issued for one MCP service was accepted there.
 */
class McpEndpointTypeGuardTest extends TestCase
{
    public function testOnlyMcpTypesAreEndpoints(): void
    {
        $this->assertTrue(McpServiceTypes::isEndpoint(McpServiceTypes::DATA));
        $this->assertTrue(McpServiceTypes::isEndpoint(McpServiceTypes::SYSTEM));
        foreach (['sqlite', 'mysql', 'local_file', 'rws', 'user', 'system', '', 'MCP'] as $type) {
            $this->assertFalse(McpServiceTypes::isEndpoint($type), "type '$type' must not be an MCP endpoint");
        }
    }

    public function testMiddlewareRejectsNonEndpointTypesBeforeRouting(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Http/Middleware/McpStreamMiddleware.php');

        $helper = strpos($source, 'private function rejectNonEndpoint(');
        $this->assertNotFalse($helper, 'middleware must define rejectNonEndpoint()');
        $body = substr($source, $helper, 900);
        $this->assertStringContainsString('McpServiceTypes::isEndpoint(', $body);
        $this->assertMatchesRegularExpression('/\],\s*404\)/', $body, 'rejection must be a 404');

        // Both entry points check it before any handler runs, so a
        // non-endpoint service never reaches token validation, OAuth
        // metadata or the daemon.
        $calls = [];
        $offset = 0;
        while (($pos = strpos($source, '$this->rejectNonEndpoint(', $offset)) !== false) {
            $calls[] = $pos;
            $offset = $pos + 1;
        }
        $this->assertCount(2, $calls, 'MCP path and RFC 8414 well-known path must both check');
        foreach (['$this->handleMcpProtocol(', '$this->handleOAuth(', '$controller->$controllerMethod('] as $handler) {
            $pos = strpos($source, $handler);
            $this->assertNotFalse($pos, "expected $handler in the middleware");
            $before = array_filter($calls, static fn ($c) => $c < $pos);
            $this->assertNotEmpty($before, "rejectNonEndpoint() must run before $handler");
        }
    }
}
