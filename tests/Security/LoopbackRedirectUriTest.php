<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use DreamFactory\Core\McpServer\Models\McpOAuthClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 8252 Section 7.3 / OAuth 2.1 draft Section 2.3.1: loopback redirect URIs.
 *
 * Native apps (Claude Code, Cursor, MCP Inspector, ...) receive the
 * authorization response on an ephemeral loopback port that they do not know
 * at registration time and that changes between runs. RFC 8252 Section 7.3:
 *
 *   "The authorization server MUST allow any port to be specified at the
 *    time of the request for loopback IP redirect URIs, to accommodate
 *    clients that obtain an available ephemeral port from the operating
 *    system at the time of the request."
 *
 * Before this fix, isValidRedirectUri() port-matched loopback URIs exactly,
 * so a native client that registered http://localhost:3118/callback and then
 * authorized from http://localhost:56104/callback was rejected — the stored
 * allowlist was permanently one port behind the client.
 *
 * The exemption is deliberately narrow. These tests pin down BOTH what it
 * allows and everything it must NOT relax.
 */
class LoopbackRedirectUriTest extends TestCase
{
    /**
     * Allowlist mirroring a real dynamic registration: Claude's fixed https
     * callbacks plus one native-client loopback entry on a stale port.
     */
    private function client(): McpOAuthClient
    {
        $client = new McpOAuthClient();
        $client->redirect_uris = [
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
            'http://localhost:3118/callback',
        ];

        return $client;
    }

    public static function allowedUriProvider(): array
    {
        return [
            'exact https match (browser client)' => ['https://claude.ai/api/mcp/auth_callback'],
            'second exact https match' => ['https://claude.com/api/mcp/auth_callback'],
            'loopback, originally registered port' => ['http://localhost:3118/callback'],
            'loopback, rotated ephemeral port (the bug)' => ['http://localhost:56104/callback'],
            'loopback, no port' => ['http://localhost/callback'],
            'loopback IPv4 literal, rotated port' => ['http://127.0.0.1:41999/callback'],
            'loopback IPv6 literal, rotated port' => ['http://[::1]:41999/callback'],
            'loopback host is case-insensitive' => ['http://LOCALHOST:41999/callback'],
            'sub-path (pre-existing prefix rule)' => ['http://localhost:56104/callback/done'],
        ];
    }

    #[DataProvider('allowedUriProvider')]
    public function testAllowsLoopbackPortRotation(string $redirectUri): void
    {
        $this->assertTrue(
            $this->client()->isValidRedirectUri($redirectUri),
            "RFC 8252 Section 7.3: {$redirectUri} must be accepted"
        );
    }

    public static function deniedUriProvider(): array
    {
        return [
            'https loopback is NOT port-exempt (RFC 8252 exemption is http-only)' => ['https://localhost:3118/callback'],
            'scheme downgrade of a registered https URI' => ['http://claude.ai/api/mcp/auth_callback'],
            'non-loopback port change stays exact' => ['https://claude.ai:8443/api/mcp/auth_callback'],
            'suffix-confusion host' => ['https://claude.ai.evil.com/api/mcp/auth_callback'],
            'loopback suffix confusion: localhost.evil.com' => ['http://localhost.evil.com/callback'],
            'loopback suffix confusion: 127.0.0.1.evil.com' => ['http://127.0.0.1.evil.com/callback'],
            'hostname merely starting with 127 is not loopback' => ['http://127foo/callback'],
            'query-origin confusion' => ['https://evil.com?https://claude.ai'],
            'path boundary: /callbackevil is not a sub-path of /callback' => ['http://localhost:9999/callbackevil'],
            'unregistered path on a loopback host' => ['http://localhost:9999/evil'],
            'non-http scheme' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('deniedUriProvider')]
    public function testLoopbackExemptionDoesNotRelaxAnythingElse(string $redirectUri): void
    {
        $this->assertFalse(
            $this->client()->isValidRedirectUri($redirectUri),
            "{$redirectUri} must still be rejected"
        );
    }

    public function testLoopbackUriIsDeniedWhenNoLoopbackEntryIsRegistered(): void
    {
        // The exemption requires at least one registered http loopback entry:
        // an operator opts a service out of loopback clients simply by never
        // registering one.
        $client = new McpOAuthClient();
        $client->redirect_uris = ['https://claude.ai/api/mcp/auth_callback'];

        $this->assertFalse(
            $client->isValidRedirectUri('http://localhost:56104/callback'),
            'Loopback port exemption must not apply when the allowlist has no http loopback entry'
        );
    }

    public function testNonLoopbackHostComparisonIsCaseInsensitive(): void
    {
        // RFC 3986 Section 3.2.2: the host subcomponent is case-insensitive.
        // Declared change in this fix: previously an uppercase spelling of a
        // registered host was rejected. Everything else about non-loopback
        // matching (scheme, port, path) stays exact.
        $this->assertTrue(
            $this->client()->isValidRedirectUri('https://CLAUDE.AI/api/mcp/auth_callback'),
            'RFC 3986 s3.2.2: host comparison must be case-insensitive'
        );
    }

    public function testEmptyAllowlistBehaviourIsUnchanged(): void
    {
        // Pre-existing dynamic-registration behaviour: an empty allowlist
        // accepts any URI. This change must not alter it either way.
        $client = new McpOAuthClient();
        $client->redirect_uris = [];

        $this->assertTrue($client->isValidRedirectUri('http://localhost:56104/callback'));
        $this->assertTrue($client->isValidRedirectUri('https://claude.ai/api/mcp/auth_callback'));
    }
}
