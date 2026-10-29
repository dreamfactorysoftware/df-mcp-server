<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use DreamFactory\Core\McpServer\Models\McpOAuthClient;
use PHPUnit\Framework\TestCase;

/**
 * Security: the first /authorize for a service creates the shared OAuth client
 * row. With no row and no admin redirect_uris, that seed must not be whatever
 * redirect_uri the first (unauthenticated) caller passed. Claude callbacks,
 * admin-configured URIs and loopback redirects (RFC 8252) are still accepted.
 */
class AuthorizeFirstUseRedirectUriTest extends TestCase
{
    private const CONFIGURED = ['https://vibe.mistral.ai/oauth/callback'];

    private function seeded(string $requested, array $configured = []): McpOAuthClient
    {
        $c = new McpOAuthClient();
        $c->redirect_uris = McpOAuthClient::firstUseRedirectUris($requested, $configured);

        return $c;
    }

    public function testAuthorizeDoesNotSeedClientFromCallerRedirectUri(): void
    {
        $m = $this->method('authorizeGet');

        $this->assertStringNotContainsString('[$redirectUri]', $m);
        $this->assertStringContainsString('McpOAuthClient::firstUseRedirectUris($redirectUri, $configuredUris)', $m);

        $seed = strpos($m, 'firstUseRedirectUris(');
        $check = strpos($m, '->isValidRedirectUri($redirectUri, $configuredUris)');
        $this->assertNotFalse($check);
        $this->assertLessThan($check, $seed);
        $this->assertLessThan(strpos($m, 'getExistingDfSession('), $check);
        $this->assertLessThan(strpos($m, 'McpOAuthAuthorizationCode::createCode('), $check);
    }

    public function testFirstUseSeedRejectsForeignOrigin(): void
    {
        $this->assertFalse($this->seeded('https://evil.example/cb')->isValidRedirectUri('https://evil.example/cb', []));
        $this->assertFalse($this->seeded('http://evil.example/cb')->isValidRedirectUri('http://evil.example/cb', []));
        $this->assertFalse($this->seeded('http://localhost.evil.com/cb')->isValidRedirectUri('http://localhost.evil.com/cb', []));
        $this->assertNotEmpty(McpOAuthClient::firstUseRedirectUris('https://evil.example/cb', []));
    }

    public function testFirstUseSeedKeepsLoopbackOnAnyPort(): void
    {
        $c = $this->seeded('http://localhost:6274/oauth/callback');
        $this->assertTrue($c->isValidRedirectUri('http://localhost:6274/oauth/callback', []));
        $this->assertTrue($c->isValidRedirectUri('http://localhost:51234/oauth/callback', []));
        $this->assertTrue($c->isValidRedirectUri('http://127.0.0.1:33418/oauth/callback', []));
        $this->assertFalse($c->isValidRedirectUri('https://evil.example/cb', []));

        $this->assertTrue($this->seeded('http://[::1]:8080/cb')->isValidRedirectUri('http://[::1]:8080/cb', []));
    }

    public function testFirstUseSeedAcceptsClaudeAndConfigured(): void
    {
        $this->assertTrue($this->seeded('https://claude.ai/api/mcp/auth_callback')
            ->isValidRedirectUri('https://claude.ai/api/mcp/auth_callback', []));

        $c = $this->seeded('https://evil.example/cb', self::CONFIGURED);
        $this->assertTrue($c->isValidRedirectUri('https://vibe.mistral.ai/oauth/callback', self::CONFIGURED));
        $this->assertFalse($c->isValidRedirectUri('https://evil.example/cb', self::CONFIGURED));
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
