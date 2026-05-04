<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: when session_token is accepted from a URL query parameter,
 * MCP responses must set Referrer-Policy: no-referrer so the URL (with
 * the token in it) does not leak to downstream pages via the Referer
 * header.
 *
 * The April 2026 audit (df-mcp-server F-04) flagged session_token in
 * URL query at oauthCallback() and oauthComplete(). The token is in the
 * URL because these endpoints are redirect targets — the originator
 * (DF login page or the OAuth provider) cannot send custom headers.
 *
 * The real fix is architectural (replace the URL token with a one-time
 * exchange code that the controller swaps for the session_token via
 * server-side cache). Pending that work, the partial mitigation is to
 * prevent the URL from leaking outward via Referer.
 *
 * This test asserts:
 *   1. The controller emits Referrer-Policy: no-referrer on the
 *      auth-success view response.
 *   2. session_token does not appear in any Log::* call as a value
 *      (already true; this is regression armor).
 */
class SessionTokenInUrlMitigationTest extends TestCase
{
    private string $controllerSrc;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Http/Controllers/McpOAuthController.php';
        $this->assertFileExists($path);
        $this->controllerSrc = file_get_contents($path);
    }

    public function testAuthSuccessResponseSetsNoReferrer(): void
    {
        // Either via a dedicated header or by setting Referrer-Policy on the
        // auth-success view response.
        $hasReferrerPolicy = preg_match(
            "/Referrer-Policy[^,)]*[,)][^'\"]*['\"]no-referrer['\"]/i",
            $this->controllerSrc
        ) === 1
        || preg_match(
            "/['\"]Referrer-Policy['\"]\s*=>\s*['\"]no-referrer['\"]/i",
            $this->controllerSrc
        ) === 1;

        $this->assertTrue(
            $hasReferrerPolicy,
            'When session_token is delivered via URL, the response must set '
            . 'Referrer-Policy: no-referrer to prevent the URL from leaking '
            . 'via the Referer header on outbound navigation.'
        );
    }

    public function testSessionTokenIsNotLoggedAsValue(): void
    {
        // Allow the literal phrase "session_token" in log messages (key name)
        // but not "$sessionToken" or "session_token=" being interpolated.
        $this->assertDoesNotMatchRegularExpression(
            "/Log::\w+\s*\(\s*['\"][^'\"]*\\\$sessionToken/",
            $this->controllerSrc,
            'Raw $sessionToken value must not be logged'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/Log::\w+\s*\([^)]*session_token['\"]\s*=>\s*\\\$sessionToken/",
            $this->controllerSrc,
            'session_token value must not be passed to Log::* in a context'
        );
    }
}
