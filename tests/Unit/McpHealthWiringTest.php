<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source wiring for GET /_internal/ai/mcp-health: registered behind
 * df.auth_check, gated to sysadmins in the controller, and the controller's
 * real I/O (2s daemon probe, node lookup) feeds Support\McpHealth.
 */
class McpHealthWiringTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function testRouteIsRegisteredBehindAuthCheckInBootingHook(): void
    {
        $s = $this->src('src/ServiceProvider.php');
        $this->assertMatchesRegularExpression(
            "/Route::middleware\('df\.auth_check'\)->get\(\s*'_internal\/ai\/mcp-health',\s*\[InternalMcpHealthController::class, 'health'\]/s",
            $s
        );
        // Same booting() registration as mcp-usage, so df-file's catch-all cannot swallow it.
        $this->assertLessThan(strpos($s, "'_internal/ai/mcp-health'"), strpos($s, '$this->app->booting(function (): void {'));
    }

    public function testControllerIsAdminOnlyAndNeverThrows(): void
    {
        $s = $this->src('src/Http/Controllers/InternalMcpHealthController.php');
        $gate = strpos($s, 'if (!Session::isSysAdmin())');
        $report = strpos($s, 'McpHealth::report(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($report);
        $this->assertLessThan($report, $gate, 'admin gate must run before any probe');
        $this->assertStringContainsString("], 403);", $s);

        $this->assertStringContainsString("'timeout'         => McpHealth::DAEMON_TIMEOUT_SECONDS", $s);
        $this->assertStringContainsString("'http_errors'     => false", $s);
        $this->assertStringContainsString('X-Mcp-Internal-Key', $s);
        $this->assertStringContainsString("new Process(['node', '--version'])", $s);
        $this->assertStringContainsString("McpHealth::forwardedOrigin(\$request->headers->get('X-Forwarded-Proto'), \$request->headers->get('X-Forwarded-Host'), \$origin)", $s);
        $this->assertStringContainsString('$p->setTimeout(McpHealth::DAEMON_TIMEOUT_SECONDS)', $s);
    }

    public function testDataDaemonHealthReportsVersion(): void
    {
        $s = $this->src('daemon/src/server.ts');
        $this->assertStringContainsString("('../package.json').version", $s);
        $this->assertMatchesRegularExpression("/app\.get\('\/health'.*?version: VERSION/s", $s);
    }
}
