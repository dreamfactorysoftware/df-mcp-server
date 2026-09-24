<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use DreamFactory\Core\McpServer\Models\McpOAuthClient;
use PHPUnit\Framework\TestCase;

/**
 * Security: /register is unauthenticated, so it must not let a caller add an
 * origin of their own to the shared OAuth client; /login must validate
 * redirect_uri before minting a code, as /authorize does. Loopback redirects
 * (RFC 8252 native apps: Claude Desktop, Claude Code, Cursor, VS Code) keep
 * working on any port.
 */
class RegisterLoginRedirectUriTest extends TestCase
{
    private const CONFIGURED = ['https://vibe.mistral.ai/oauth/callback'];

    private function client(array $uris): McpOAuthClient
    {
        $c = new McpOAuthClient();
        $c->redirect_uris = $uris;

        return $c;
    }

    public function testRegisterDropsForeignOriginsKeepsClaudeConfiguredAndLoopback(): void
    {
        $uris = McpOAuthClient::registrableRedirectUris(
            ['https://evil.example/cb', 'http://localhost:6274/oauth/callback', 'http://evil.example/cb', 'cursor://x/cb'],
            [],
            self::CONFIGURED
        );

        $this->assertSame([
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
            'https://vibe.mistral.ai/oauth/callback',
            'http://localhost:6274/oauth/callback',
        ], $uris);

        $c = $this->client($uris);
        $this->assertFalse($c->isValidRedirectUri('https://evil.example/cb'));
        $this->assertTrue($c->isValidRedirectUri('https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue($c->isValidRedirectUri('https://vibe.mistral.ai/oauth/callback'));
        // Native client came back on a new ephemeral port / other loopback spelling.
        $this->assertTrue($c->isValidRedirectUri('http://localhost:51234/oauth/callback'));
        $this->assertTrue($c->isValidRedirectUri('http://127.0.0.1:33418/oauth/callback'));
        $this->assertFalse($c->isValidRedirectUri('http://localhost:51234/elsewhere'), 'path still has to match');
    }

    public function testRegisterPrunesPreviouslyInjectedOriginsButKeepsStoredLoopback(): void
    {
        $uris = McpOAuthClient::registrableRedirectUris(
            [],
            ['https://attacker.example/cb', 'http://127.0.0.1:3118/callback', 'https://claude.ai/api/mcp/auth_callback'],
            []
        );

        $this->assertNotContains('https://attacker.example/cb', $uris);
        $this->assertContains('http://127.0.0.1:3118/callback', $uris);
        $this->assertCount(3, $uris, 'no duplicates');
    }

    public function testLoopbackLookalikesAreNotLoopback(): void
    {
        foreach (['https://localhost/cb', 'http://localhost.evil.com/cb', 'http://127.0.0.1.evil.com/cb', 'http://evil.com/?http://localhost', 'not a uri'] as $uri) {
            $this->assertFalse(McpOAuthClient::isLoopbackRedirectUri($uri), $uri);
        }
        foreach (['http://localhost:1/cb', 'http://127.0.0.1/', 'http://[::1]:8080/cb', 'http://LOCALHOST:2/'] as $uri) {
            $this->assertTrue(McpOAuthClient::isLoopbackRedirectUri($uri), $uri);
        }
    }

    public function testRegisterUsesTheHelperAndNoLongerMergesCallerUris(): void
    {
        $method = $this->method('register');
        $this->assertStringContainsString('McpOAuthClient::registrableRedirectUris(', $method);
        $this->assertStringContainsString("'redirect_uris' => \$allowedUris", $method);
        $this->assertStringNotContainsString('array_merge($existingUris', $method);
    }

    public function testLoginValidatesClientAndRedirectUriBeforeAuthenticating(): void
    {
        $method = $this->method('login');
        $auth = strpos($method, '$this->authenticateWithDreamFactory(');
        $this->assertNotFalse($auth);
        foreach ([
            "\$clientId !== \$serviceConfig['oauth_client_id']",
            "in_array(parse_url(\$redirectUri, PHP_URL_SCHEME), ['http', 'https'], true)",
            '->isValidRedirectUri($redirectUri, $configuredUris)',
        ] as $needle) {
            $at = strpos($method, $needle);
            $this->assertNotFalse($at, $needle);
            $this->assertLessThan($auth, $at, "{$needle} must run before credentials are checked and a code is minted");
        }
    }

    private function method(string $name): string
    {
        $src = file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpOAuthController.php');
        $start = strpos($src, "public function {$name}(");
        $this->assertNotFalse($start);
        $end = strpos($src, "\n    public function ", $start + 1);

        return substr($src, $start, $end === false ? null : $end - $start);
    }
}
