<?php

namespace DreamFactory\Core\McpServer\Tests\Client;

use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use PHPUnit\Framework\TestCase;

/**
 * In stateless daemon mode the lazy facade never sees initialize.clientInfo on
 * the tools/list request, so the PHP proxy must tell the daemon who is calling
 * on every request via X-Mcp-Client-Name. The name is resolved server-side
 * (OAuth client registration, or the API-key app) and must be header-safe.
 */
class ClientNameHeaderTest extends TestCase
{
    public function testHeaderValueIsPrintableAsciiAndBounded(): void
    {
        $this->assertSame('Codex CLI', McpDaemonClient::clientHeaderValue("  Codex\r\n CLI\u{1F600} "));
        $this->assertNull(McpDaemonClient::clientHeaderValue(null));
        $this->assertNull(McpDaemonClient::clientHeaderValue("\u{1F600}"));
        $this->assertSame(128, strlen((string) McpDaemonClient::clientHeaderValue(str_repeat('a', 300))));
    }

    public function testNoAuthContextYieldsNoHeader(): void
    {
        $this->assertSame([], McpDaemonClient::clientNameHeader(['auth_type' => 'oauth', 'token' => null, 'app_id' => null]));
    }

    public function testProxyRequestForwardsTheClientNameOnEveryRequest(): void
    {
        $client = file_get_contents(__DIR__ . '/../../src/Client/McpDaemonClient.php');
        $proxy = substr($client, strpos($client, 'public function proxyRequest'), strpos($client, 'public function rpcStateless') - strpos($client, 'public function proxyRequest'));

        $this->assertStringContainsString('self::clientNameHeader($authResult)', $proxy, 'proxyRequest sends X-Mcp-Client-Name');
        $this->assertStringContainsString('RequestLogger::resolveClientName($token)', $client, 'OAuth: registered client_name');
        $this->assertStringContainsString("App::find(\$appId)?->name", $client, 'API key: app name');
        $this->assertStringContainsString("\$headers['X-Mcp-Client-Name'] = 'df-ai-chat'", $client, 'rpc bridge identifies itself');

        $logger = file_get_contents(__DIR__ . '/../../src/Utility/RequestLogger.php');
        $this->assertStringContainsString('public static function resolveClientName', $logger);

        $daemon = file_get_contents(__DIR__ . '/../../daemon/src/server.ts');
        $this->assertStringContainsString("req.header('x-mcp-client-name')", $daemon, 'daemon reads the hint in stateless mode');
    }
}
