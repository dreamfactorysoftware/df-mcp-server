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

    /**
     * Configured redirect URIs (df-mcp-server: "Allowed Redirect URIs" on the
     * service config) must widen the allowlist without weakening the matching
     * rules. Clients that do not perform dynamic client registration — Mistral
     * Vibe posts straight to /authorize — otherwise depend on winning a race to
     * be the first client to authorize against the service.
     */
    public function testIsValidRedirectUriHonorsAdditionalConfiguredUris(): void
    {
        $client = new \DreamFactory\Core\McpServer\Models\McpOAuthClient();
        $client->redirect_uris = ['https://claude.ai/api/mcp/auth_callback'];

        $configured = ['https://callback.mistral.ai/v1/integrations_auth/oauth2_callback'];

        $this->assertFalse(
            $client->isValidRedirectUri('https://callback.mistral.ai/v1/integrations_auth/oauth2_callback'),
            'Precondition: the URI must not already be registered on the client'
        );
        $this->assertTrue(
            $client->isValidRedirectUri(
                'https://callback.mistral.ai/v1/integrations_auth/oauth2_callback',
                $configured
            ),
            'A redirect_uri configured on the service must be accepted'
        );
        $this->assertTrue(
            $client->isValidRedirectUri('https://claude.ai/api/mcp/auth_callback', $configured),
            'Dynamically registered URIs must keep working alongside configured ones'
        );
        $this->assertFalse(
            $client->isValidRedirectUri('https://evil.example.com/cb', $configured),
            'Configured URIs must not open the allowlist to unrelated origins'
        );
        $this->assertFalse(
            $client->isValidRedirectUri('https://callback.mistral.ai.evil.example.com/cb', $configured),
            'Host matching must stay exact — a lookalike suffix must not match'
        );
    }

    public function testAuthorizePassesConfiguredUrisToValidator(): void
    {
        $this->assertMatchesRegularExpression(
            '/->isValidRedirectUri\s*\(\s*\$redirectUri\s*,\s*\$configuredUris\s*\)/',
            $this->controllerSrc,
            'authorizeGet() must pass the service-configured redirect URIs into '
            . 'isValidRedirectUri(), otherwise the admin-managed allowlist is ignored.'
        );
    }

    public function testNormalizeRedirectUrisDropsUnsafeEntries(): void
    {
        $configClass = \DreamFactory\Core\McpServer\Models\McpServerConfig::class;
        if (!class_exists($configClass)) {
            $this->markTestSkipped('McpServerConfig requires df-core to be autoloadable');
        }

        $this->assertSame(
            ['https://callback.mistral.ai/cb', 'http://localhost:8080/cb'],
            $configClass::normalizeRedirectUris([
                'https://callback.mistral.ai/cb',
                '  ',
                'javascript:alert(1)',
                'file:///etc/passwd',
                'not-a-url',
                'http://localhost:8080/cb',
                'https://callback.mistral.ai/cb',
            ]),
            'normalizeRedirectUris() must drop blanks, non-absolute values, and '
            . 'non-http(s) schemes, and de-duplicate what remains'
        );

        $this->assertSame(
            ['https://a.example.com/cb', 'https://b.example.com/cb'],
            $configClass::normalizeRedirectUris("https://a.example.com/cb\nhttps://b.example.com/cb"),
            'A newline-separated string must be accepted (raw textarea input)'
        );

        $this->assertSame([], $configClass::normalizeRedirectUris(null));
    }
}
