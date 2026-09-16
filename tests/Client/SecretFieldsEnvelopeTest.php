<?php

namespace DreamFactory\Core\McpServer\Tests\Client;

use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use PHPUnit\Framework\TestCase;

/**
 * The System API MCP daemon masks service configs with a secret field manifest that
 * McpDaemonClient sends in the POST envelope as `_mcpSecretFields`. Other daemons never get it.
 */
class SecretFieldsEnvelopeTest extends TestCase
{
    private const MANIFEST = ['gcm' => ['secret' => ['api_key', 'certificate'], 'maps' => []]];

    /** The constructor reads Laravel config, so build the client without it. */
    private function client(): McpDaemonClient
    {
        return (new \ReflectionClass(McpDaemonClient::class))->newInstanceWithoutConstructor();
    }

    private function envelope(McpDaemonClient $client, array $payload): array
    {
        $method = new \ReflectionMethod(McpDaemonClient::class, 'envelope');

        return json_decode(json_encode($method->invoke($client, $payload, ['disabled_tools' => []], [])), true);
    }

    public function testEnvelopeCarriesManifestWhenSet(): void
    {
        $client = $this->client()->withSecretFields(self::MANIFEST);

        $envelope = $this->envelope($client, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->assertSame(self::MANIFEST, $envelope['_mcpSecretFields']);
        $this->assertSame('tools/list', $envelope['_mcpPayload']['method']);
        $this->assertSame([], $envelope['_mcpAvailableServices']);
    }

    public function testEnvelopeOmitsManifestByDefaultOrWhenEmpty(): void
    {
        $this->assertArrayNotHasKey('_mcpSecretFields', $this->envelope($this->client(), ['id' => 1]));
        $this->assertArrayNotHasKey('_mcpSecretFields', $this->envelope($this->client()->withSecretFields([]), ['id' => 1]));
    }

    public function testBothProxyPathsBuildTheSameEnvelope(): void
    {
        $s = file_get_contents(__DIR__ . '/../../src/Client/McpDaemonClient.php');

        $this->assertSame(2, substr_count($s, '$this->envelope('));
        $this->assertStringNotContainsString("'_mcpPayload'           =>", $s);
    }
}
