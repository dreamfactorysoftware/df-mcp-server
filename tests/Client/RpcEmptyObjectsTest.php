<?php

namespace DreamFactory\Core\McpServer\Tests\Client;

use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use PHPUnit\Framework\TestCase;

/**
 * The session-authed /rpc bridge (Mcp::handleRpcBridge) decodes the incoming
 * JSON-RPC body with json_decode(..., true), which collapses every empty JSON
 * object to a PHP empty array. Re-serialized, those become [] instead of {},
 * and the daemon's zod validation rejects the message. The top-level `params`
 * case was already handled; an empty `arguments` object inside params (an
 * argument-less tools/call) was not, so any zero-argument MCP tool call made
 * through the first-party bridge (e.g. from df-ai-chat) failed.
 */
class RpcEmptyObjectsTest extends TestCase
{
    public function testEmptyTopLevelParamsSerializeAsObject(): void
    {
        $decoded = json_decode('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}', true);

        $restored = McpDaemonClient::restoreEmptyJsonObjects($decoded);

        $this->assertSame('{}', json_encode($restored['params']));
    }

    public function testEmptyToolCallArgumentsSerializeAsObject(): void
    {
        $decoded = json_decode(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_table_list","arguments":{}}}',
            true
        );

        $restored = McpDaemonClient::restoreEmptyJsonObjects($decoded);

        $this->assertSame(
            '{"name":"get_table_list","arguments":{}}',
            json_encode($restored['params'])
        );
    }

    public function testNonEmptyArgumentsAreUntouched(): void
    {
        $decoded = json_decode(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_table_data","arguments":{"table":"orders"}}}',
            true
        );

        $restored = McpDaemonClient::restoreEmptyJsonObjects($decoded);

        $this->assertSame(['table' => 'orders'], $restored['params']['arguments']);
    }

    public function testMissingParamsAreUntouched(): void
    {
        $decoded = json_decode('{"jsonrpc":"2.0","id":1,"method":"ping"}', true);

        $restored = McpDaemonClient::restoreEmptyJsonObjects($decoded);

        $this->assertArrayNotHasKey('params', $restored);
    }
}
