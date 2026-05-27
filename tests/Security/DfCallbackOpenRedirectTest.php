<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: dfCallback() must validate client_id and redirect_uri before
 * minting an authorization code.
 *
 * The April 2026 audit (df-mcp-server F-06) found that
 * `McpOAuthController::dfCallback()` accepts `client_id` and `redirect_uri`
 * from the POST body with NO validation. A caller with a valid DF session
 * token could mint an authorization code bound to ANY client + ANY
 * redirect_uri, then complete the auth flow on the attacker's terms — an
 * open-redirect / authorization-code-theft primitive.
 *
 * The fix applies the same checks the /authorize endpoint uses (QW-7 of
 * Round 1):
 *   1. redirect_uri scheme must be http or https
 *   2. client_id must resolve to a registered McpOAuthClient
 *   3. redirect_uri must validate against that client's registered URIs
 *      (delegated to McpOAuthClient::isValidRedirectUri()).
 */
class DfCallbackOpenRedirectTest extends TestCase
{
    private string $controllerSrc;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Http/Controllers/McpOAuthController.php';
        $this->assertFileExists($path);
        $this->controllerSrc = file_get_contents($path);
    }

    /**
     * Slice the controller source to just the dfCallback() method body.
     */
    private function dfCallbackBody(): string
    {
        $start = strpos($this->controllerSrc, 'function dfCallback');
        $this->assertNotFalse($start, 'dfCallback() must exist');
        // Find the next function definition or end of file.
        $next = strpos($this->controllerSrc, 'function ', $start + 10);
        return substr(
            $this->controllerSrc,
            $start,
            $next === false ? null : ($next - $start)
        );
    }

    public function testDfCallbackEnforcesHttpOrHttpsScheme(): void
    {
        $body = $this->dfCallbackBody();

        $hasInArrayHttpsCheck = preg_match(
            "/in_array\s*\([^,]+,\s*\[\s*['\"]https?['\"]\s*,\s*['\"]https?['\"]\s*\]/",
            $body
        ) === 1;
        $hasRegexCheck = preg_match(
            '/preg_match\s*\(\s*[\'"][^\'"]*\^https\?:[^\'"]*[\'"]\s*,/',
            $body
        ) === 1;

        $this->assertTrue(
            $hasInArrayHttpsCheck || $hasRegexCheck,
            'dfCallback() must restrict redirect_uri scheme to http/https before '
            . 'minting an authorization code.'
        );
    }

    public function testDfCallbackLooksUpClientById(): void
    {
        $body = $this->dfCallbackBody();
        $this->assertMatchesRegularExpression(
            '/McpOAuthClient::findByClientId\s*\(/',
            $body,
            'dfCallback() must verify client_id resolves to a registered OAuth '
            . 'client. Without this, any DF-authenticated user can mint codes '
            . 'for arbitrary client_ids.'
        );
    }

    public function testDfCallbackValidatesRedirectUriAgainstClient(): void
    {
        $body = $this->dfCallbackBody();
        $this->assertMatchesRegularExpression(
            '/->isValidRedirectUri\s*\(/',
            $body,
            'dfCallback() must call $client->isValidRedirectUri($redirectUri) — '
            . 'otherwise the auth code can be redirected anywhere the caller '
            . 'specifies, even when client_id is otherwise valid.'
        );
    }
}
