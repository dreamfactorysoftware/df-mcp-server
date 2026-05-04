<?php

namespace DreamFactory\Core\McpServer\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: MCP wildcard CORS is safe ONLY because Allow-Credentials is not
 * also set to true. This test prevents accidental regression.
 *
 * The April 2026 audit (df-mcp-server F-02) flagged the wildcard
 * `Access-Control-Allow-Origin: *` on MCP endpoints. The wildcard is
 * intentional — MCP clients (Claude Desktop, ChatGPT, etc.) connect from
 * arbitrary origins and authenticate via Bearer tokens, not cookies.
 *
 * Per the CORS spec, wildcard `*` with `Allow-Credentials: true` is
 * forbidden by browsers — browsers refuse to expose responses for
 * credentialed requests when origin is `*`. So the wildcard is safe AS
 * LONG AS no future change adds Allow-Credentials.
 *
 * This test fails if Allow-Credentials is added — turning the wildcard
 * into a credentialed-CORS misconfiguration that exposes session-token
 * responses to any origin.
 */
class CorsCredentialsGuardTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Http/Middleware/McpStreamMiddleware.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testWildcardOriginIsStillInUse(): void
    {
        // Sanity: this guard only matters while wildcard is in use.
        $this->assertMatchesRegularExpression(
            "/'Access-Control-Allow-Origin'\s*=>\s*'\*'/",
            $this->contents
        );
    }

    public function testAllowCredentialsIsNotSetTrue(): void
    {
        // Browsers forbid wildcard + credentials. If a future commit adds
        // `Access-Control-Allow-Credentials: true`, the browser still rejects
        // the response, BUT some misconfigurations also widen the wildcard
        // to a single origin reflected back from the request. Either pattern
        // exposes session tokens to cross-origin readers.
        $this->assertDoesNotMatchRegularExpression(
            "/'Access-Control-Allow-Credentials'\s*=>\s*['\"]true['\"]/i",
            $this->contents,
            'Setting Allow-Credentials: true with wildcard origin breaks the '
            . 'safety guarantee of public CORS — disallowed.'
        );
    }
}
