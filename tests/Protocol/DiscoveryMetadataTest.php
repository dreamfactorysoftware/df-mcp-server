<?php

namespace DreamFactory\Core\McpServer\Tests\Protocol;

use PHPUnit\Framework\TestCase;

/**
 * RFC 9728 lists scopes_supported as RECOMMENDED in the protected resource
 * metadata document (only `resource` is REQUIRED). It is not decorative: the
 * MCP TypeScript SDK reads it to build the scope it requests —
 *
 *   const resolvedScope = scope
 *       || resourceMetadata?.scopes_supported?.join(' ')
 *       || provider.clientMetadata.scope;
 *
 * — so omitting it silently pushes clients onto their own configured scope.
 * The authorization server metadata (RFC 8414) already advertised the scopes,
 * which made the two documents disagree about the same server.
 */
class DiscoveryMetadataTest extends TestCase
{
    private string $controllerSrc;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Http/Controllers/McpOAuthController.php';
        $this->assertFileExists($path);
        $this->controllerSrc = file_get_contents($path);
    }

    public function testProtectedResourceMetadataAdvertisesScopes(): void
    {
        $start = strpos($this->controllerSrc, 'public function protectedResourceMetadata');
        $this->assertNotFalse($start, 'protectedResourceMetadata() must exist');
        $next = strpos($this->controllerSrc, 'public function ', $start + 10);
        $body = substr($this->controllerSrc, $start, $next === false ? null : $next - $start);

        $this->assertStringContainsString(
            "'scopes_supported'",
            $body,
            'RFC 9728 protected resource metadata should advertise scopes_supported; '
            . 'MCP clients read it to build their scope request.'
        );
        $this->assertStringContainsString(
            "'resource'",
            $body,
            'resource is the one REQUIRED field in RFC 9728 metadata'
        );
    }

    public function testBothMetadataDocumentsShareASingleScopeConstant(): void
    {
        $this->assertMatchesRegularExpression(
            '/private const SUPPORTED_SCOPES\s*=\s*\[/',
            $this->controllerSrc,
            'The scope list must live in one constant so the protected-resource and '
            . 'authorization-server documents cannot drift apart.'
        );
        $this->assertSame(
            2,
            substr_count($this->controllerSrc, "'scopes_supported' => self::SUPPORTED_SCOPES"),
            'Both metadata documents must source scopes_supported from the shared constant.'
        );
    }

    public function testAuthorizeDefaultScopeDerivesFromTheSameConstant(): void
    {
        $this->assertStringContainsString(
            "implode(' ', self::SUPPORTED_SCOPES)",
            $this->controllerSrc,
            "authorizeGet()'s default scope must derive from the same constant rather "
            . 'than repeating the scope string literally.'
        );
    }

    public function testScopeConstantHoldsTheMcpScopes(): void
    {
        $class = \DreamFactory\Core\McpServer\Http\Controllers\McpOAuthController::class;
        if (!class_exists($class)) {
            $this->markTestSkipped('McpOAuthController requires df-core to be autoloadable');
        }

        $constant = (new \ReflectionClass($class))->getConstant('SUPPORTED_SCOPES');

        $this->assertSame(['mcp:tools', 'mcp:resources', 'mcp:prompts'], $constant);
    }
}
