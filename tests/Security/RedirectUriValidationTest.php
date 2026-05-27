<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: McpOAuthController.authorizeGet() must validate the redirect_uri
 * before issuing an authorization code.
 *
 * The April 2026 audit (df-mcp-server F-05) found that
 * `McpOAuthClient::isValidRedirectUri()` was implemented but never called from
 * the controller. The controller used a bare `in_array($redirectUri,
 * $registeredUris, true)` check that:
 *
 *   - skipped validation entirely when the client had no registered URIs
 *     (creating a TOFU/first-write-wins registration window an attacker
 *     can race),
 *   - performed no scheme validation at all (a redirect_uri of
 *     `javascript:alert(1)` or `file:///etc/passwd` would pass the
 *     in_array check provided it was registered).
 *
 * After the fix:
 *
 *   1. The controller delegates to the model's isValidRedirectUri() helper
 *      which parses scheme, host, and port and applies strict origin matching.
 *   2. The redirect_uri scheme is restricted to http or https before any
 *      registration logic runs — preventing javascript:, data:, file://, etc.
 */
class RedirectUriValidationTest extends TestCase
{
    private string $controllerPath;
    private string $controllerSrc;

    protected function setUp(): void
    {
        $this->controllerPath = __DIR__ . '/../../src/Http/Controllers/McpOAuthController.php';
        $this->assertFileExists($this->controllerPath);
        $this->controllerSrc = file_get_contents($this->controllerPath);
    }

    public function testControllerCallsIsValidRedirectUriOnClientModel(): void
    {
        $this->assertMatchesRegularExpression(
            '/->isValidRedirectUri\s*\(/',
            $this->controllerSrc,
            'McpOAuthController must call $client->isValidRedirectUri(...) — the '
            . 'method exists on the model and applies strict origin matching, '
            . 'but the controller previously never invoked it.'
        );
    }

    public function testControllerEnforcesHttpOrHttpsScheme(): void
    {
        // We require the controller to reject schemes other than http/https
        // before any registration / lookup logic runs. We accept either an
        // in_array check or a preg_match against http(s)://.
        $hasInArrayHttpsCheck = preg_match(
            "/in_array\s*\(\s*[^,]+,\s*\[\s*['\"]https['\"]\s*,\s*['\"]http['\"]\s*\]/",
            $this->controllerSrc
        ) === 1;
        $hasInArrayHttpsCheckReversed = preg_match(
            "/in_array\s*\(\s*[^,]+,\s*\[\s*['\"]http['\"]\s*,\s*['\"]https['\"]\s*\]/",
            $this->controllerSrc
        ) === 1;
        $hasRegexCheck = preg_match(
            '/preg_match\s*\(\s*[\'"][^\'"]*\^https\?:[^\'"]*[\'"]\s*,/',
            $this->controllerSrc
        ) === 1;

        $this->assertTrue(
            $hasInArrayHttpsCheck || $hasInArrayHttpsCheckReversed || $hasRegexCheck,
            'McpOAuthController must restrict redirect_uri scheme to http/https. '
            . 'Without this, an attacker can pass javascript:, data:, file://, etc.'
        );
    }

    public function testIsValidRedirectUriRejectsJavascriptScheme(): void
    {
        // Demonstrate that the model's helper, once called, rejects bypass
        // payloads. We instantiate the model with a registered https URI
        // and check that the dangerous scheme is rejected.
        $modelClass = \DreamFactory\Core\McpServer\Models\McpOAuthClient::class;
        $this->assertTrue(class_exists($modelClass), 'McpOAuthClient model must exist');

        $client = new $modelClass();
        $client->redirect_uris = ['https://chatgpt.com/oauth/callback'];

        $this->assertFalse(
            $client->isValidRedirectUri('javascript:alert(1)'),
            'isValidRedirectUri() must reject javascript: scheme'
        );
        $this->assertFalse(
            $client->isValidRedirectUri('file:///etc/passwd'),
            'isValidRedirectUri() must reject file:// scheme'
        );
        $this->assertFalse(
            $client->isValidRedirectUri('https://evil.com/?foo=https://chatgpt.com'),
            'isValidRedirectUri() must reject same-string-different-origin payloads'
        );
    }
}
