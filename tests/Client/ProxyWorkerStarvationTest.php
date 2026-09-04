<?php

namespace DreamFactory\Core\McpServer\Tests\Client;

use PHPUnit\Framework\TestCase;

/**
 * Issue #50: under a few concurrent MCP sessions the daemon "stalled" for ~5
 * minutes. Every MCP request is proxied by a PHP-FPM worker that blocks on
 * the daemon; the daemon calls back into DreamFactory over HTTP, needing a
 * second worker from the same pool. Two things turned that into a 300s hang:
 *
 *  1. Clients open a standalone GET SSE stream per session. The daemon never
 *     ends that stream and Guzzle (non-streaming) buffered it, so each session
 *     pinned a worker until the 300s Guzzle timeout ("cURL error 28 ... with
 *     247 bytes received"). GET must now be declined with 405 (spec-allowed).
 *  2. OAuth login / session validation / environment lookup made their own
 *     HTTP round trips to APP_URL, consuming yet another worker, so a busy
 *     pool reported "Invalid credentials" for a valid password. They must run
 *     in-process.
 *
 * Source-level assertions, matching the rest of this suite (no app container).
 */
class ProxyWorkerStarvationTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);
        return file_get_contents($path);
    }

    public function testGetDeclinesServerInitiatedSseStreamWith405(): void
    {
        $src = $this->src('src/Http/Controllers/McpStreamController.php');
        $start = strpos($src, 'public function handleGet(');
        $end = strpos($src, 'public function handlePost(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, $end - $start);

        // Token still validated first so unauthenticated GETs get 401 + WWW-Authenticate.
        $this->assertStringContainsString('validateBearerToken', $body);
        $this->assertStringContainsString('405', $body);
        $this->assertStringContainsString("'Allow'", $body);
        // The GET must never reach the daemon proxy.
        $this->assertStringNotContainsString('processMcpRequest', $body);
    }

    public function testOAuthControllerDoesNotCallBackOverHttp(): void
    {
        $src = $this->src('src/Http/Controllers/McpOAuthController.php');

        $this->assertStringNotContainsString('GuzzleHttp', $src);
        // The only remaining /api/v2/user/session reference is a browser
        // redirect URL for external OAuth providers, never a server-side call.
        $this->assertStringNotContainsString('->post(', $src, 'login must not round-trip through the FPM pool');
        $this->assertStringNotContainsString('->get("', $src);
        $this->assertStringContainsString('ServiceManager::handleRequest(', $src);
        $this->assertStringContainsString('JWTAuth::getPayload()', $src);
        $this->assertStringContainsString('JWTUtilities::verifyUser(', $src);
    }

    public function testDaemonTimeoutIsConfigurableAndTimeoutIsNotReportedAsDaemonDown(): void
    {
        $client = $this->src('src/Client/McpDaemonClient.php');
        $config = $this->src('config/mcp.php');

        $this->assertStringContainsString("'timeout' => (int) env('MCP_DAEMON_TIMEOUT', 300)", $config);
        $this->assertStringContainsString("config('mcp.daemon.timeout'", $client);
        $this->assertStringNotContainsString("'timeout' => 300", $client);
        $this->assertStringContainsString('CURLE_OPERATION_TIMEOUTED', $client);
        $this->assertStringContainsString('504', $client);
    }
}
