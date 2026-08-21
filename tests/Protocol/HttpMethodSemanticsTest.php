<?php

namespace DreamFactory\Core\McpServer\Tests\Protocol;

use PHPUnit\Framework\TestCase;

/**
 * RFC 9110 sec. 9.3.2: a HEAD response is identical to the GET response for the
 * same target, minus the body. Same status, same headers.
 *
 * McpStreamMiddleware intercepts /mcp/* before DreamFactory's routing. Its
 * method dispatch originally matched only GET/POST/DELETE and returned null for
 * anything else, which fell through to DF's routing and answered HEAD with 404 —
 * hiding the 401 + WWW-Authenticate that drives OAuth discovery. The RFC 8414
 * canonical well-known handler had the same gap, guarding on $method === 'GET'.
 *
 * These are structural assertions over the source: the behaviour needs a booted
 * Laravel request lifecycle, which this package's test suite does not stand up.
 */
class HttpMethodSemanticsTest extends TestCase
{
    private string $middlewareSrc;
    private string $controllerSrc;

    protected function setUp(): void
    {
        $middleware = __DIR__ . '/../../src/Http/Middleware/McpStreamMiddleware.php';
        $controller = __DIR__ . '/../../src/Http/Controllers/McpStreamController.php';
        $this->assertFileExists($middleware);
        $this->assertFileExists($controller);
        $this->middlewareSrc = file_get_contents($middleware);
        $this->controllerSrc = file_get_contents($controller);
    }

    public function testMiddlewareDispatchesHeadToItsOwnHandler(): void
    {
        $this->assertMatchesRegularExpression(
            "/'HEAD'\s*=>\s*\\\$controller->handleHead\s*\(/",
            $this->middlewareSrc,
            'handleMcpProtocol() must have a HEAD arm; without one HEAD falls through '
            . 'to DreamFactory routing and returns 404 instead of mirroring GET.'
        );
    }

    public function testStreamControllerDefinesHeadHandler(): void
    {
        $this->assertMatchesRegularExpression(
            '/public function handleHead\s*\(/',
            $this->controllerSrc,
            'McpStreamController must expose handleHead()'
        );
    }

    public function testHeadHandlerDoesNotOpenADaemonSession(): void
    {
        // Isolate handleHead()'s body and assert it never reaches the daemon proxy.
        $start = strpos($this->controllerSrc, 'public function handleHead');
        $this->assertNotFalse($start, 'handleHead() must exist');
        $next = strpos($this->controllerSrc, 'public function ', $start + 10);
        $body = substr($this->controllerSrc, $start, $next === false ? null : $next - $start);

        $this->assertStringNotContainsString(
            'processMcpRequest',
            $body,
            'handleHead() must not call processMcpRequest() — a HEAD probe must not '
            . 'open an MCP session against the daemon as a side effect.'
        );
        $this->assertStringContainsString(
            'validateBearerToken',
            $body,
            'handleHead() must still run the same auth check as GET so it returns 401 '
            . '+ WWW-Authenticate to unauthenticated callers.'
        );
    }

    public function testRfc8414WellKnownHandlerAcceptsHead(): void
    {
        $this->assertMatchesRegularExpression(
            "/in_array\s*\(\s*\\\$method\s*,\s*\[\s*'GET'\s*,\s*'HEAD'\s*\]/",
            $this->middlewareSrc,
            'handleRfc8414WellKnown() must accept HEAD as well as GET; guarding on '
            . "\$method === 'GET' made HEAD on /.well-known/...\/mcp/{service} return 404."
        );
    }

    public function testHeadResponsesHaveTheirBodyStripped(): void
    {
        $this->assertMatchesRegularExpression(
            '/private static function stripBodyForHead\s*\(/',
            $this->middlewareSrc,
            'The middleware returns responses directly rather than through the router, '
            . "so Symfony's prepare() body-stripping never runs; it must strip HEAD bodies itself."
        );
        $this->assertSame(
            2,
            substr_count($this->middlewareSrc, 'return self::stripBodyForHead('),
            'Both response return paths (MCP protocol + RFC 8414 well-known) must strip HEAD bodies.'
        );
    }

    public function testCorsAdvertisesHead(): void
    {
        $this->assertMatchesRegularExpression(
            "/'Access-Control-Allow-Methods'\s*=>\s*'[^']*\bHEAD\b/",
            $this->middlewareSrc,
            'HEAD is now handled, so it belongs in Access-Control-Allow-Methods.'
        );
    }
}
