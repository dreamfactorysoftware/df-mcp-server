<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Support\McpHealth;
use PHPUnit\Framework\TestCase;

/**
 * GET /_internal/ai/mcp-health report, built with fake daemon probes and a
 * fake node lookup. Standalone-safe (Support\ only).
 */
class McpHealthTest extends TestCase
{
    private const ORIGIN = 'https://df.example.com';

    private function config(array $over = []): array
    {
        return array_replace_recursive([
            'daemon'        => ['enabled' => true, 'url' => 'http://127.0.0.1:8006', 'internal_base_url' => null, 'internal_key' => null,
                // What the controller adds: the generated key file exists.
                'internal_key_resolved' => true, 'internal_key_file' => '/opt/df/storage/framework/mcp_internal_key'],
            'system_daemon' => ['enabled' => true, 'url' => 'http://127.0.0.1:3700', 'base_url' => null],
        ], $over);
    }

    /** Probe that answers every daemon with a healthy body. */
    private function upProbe(): callable
    {
        return function (string $url): array {
            $body = str_contains($url, ':3700')
                ? ['status' => 'healthy', 'version' => '2.1.0', 'tools' => 42, 'mode' => 'stateful']
                : ['status' => 'ok', 'version' => '1.0.0', 'mode' => 'stateless'];

            return ['status' => 200, 'body' => json_encode($body)];
        };
    }

    private function check(array $report, string $id): array
    {
        foreach ($report['checks'] as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        $this->fail("check {$id} missing");
    }

    public function testAllGreen(): void
    {
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $this->upProbe(), fn () => 'v20.11.0');

        $this->assertSame('ok', $r['status']);
        $this->assertCount(2, $r['daemons']);
        [$data, $system] = $r['daemons'];
        $this->assertSame('mcp', $data['type']);
        $this->assertTrue($data['reachable']);
        $this->assertSame('1.0.0', $data['version']);
        $this->assertSame('stateless', $data['mode']);
        $this->assertNull($data['tools']);
        $this->assertIsInt($data['latency_ms']);
        $this->assertSame('system_mcp', $system['type']);
        $this->assertSame(42, $system['tools']);
        $this->assertSame('2.1.0', $system['version']);
        $this->assertNull($system['error']);
        $this->assertSame('ok', $this->check($r, 'node')['status']);
        $this->assertTrue($this->check($r, 'stateless')['details']['stateless']);
        foreach ($r['checks'] as $c) {
            $this->assertSame(['id', 'status', 'message', 'details'], array_keys($c));
            $this->assertNotSame('', $c['message']);
        }
    }

    public function testUnreachableDaemonIsAnErrorThatNamesTheFix(): void
    {
        $probe = function (string $url): array {
            throw new \RuntimeException('Connection refused');
        };
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $probe, fn () => 'v20.11.0');

        $this->assertSame('error', $r['status']);
        $this->assertFalse($r['daemons'][0]['reachable']);
        $this->assertSame('Connection refused', $r['daemons'][0]['error']);
        $c = $this->check($r, 'daemon.mcp');
        $this->assertSame('error', $c['status']);
        $this->assertStringContainsString('MCP_DAEMON_URL', $c['message']);
        $this->assertStringContainsString('scripts/start-daemon.sh', $c['message']);
        $this->assertStringContainsString('MCP_SYSTEM_DAEMON_URL', $this->check($r, 'daemon.system_mcp')['message']);
    }

    public function testTimeoutNeverThrowsAndRecordsLatency(): void
    {
        $probe = function (string $url): array {
            usleep(20000);
            throw new \RuntimeException('cURL error 28: Operation timed out after 2000 ms');
        };
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $probe, fn () => null);

        $this->assertSame('error', $r['status']);
        $this->assertGreaterThanOrEqual(20, $r['daemons'][0]['latency_ms']);
        $this->assertStringContainsString('timed out', $r['daemons'][0]['error']);
    }

    public function testNon2xxAndNonJsonAreUnreachable(): void
    {
        $probe = fn (string $url) => str_contains($url, ':3700')
            ? ['status' => 502, 'body' => 'bad gateway']
            : ['status' => 200, 'body' => '<html>nginx</html>'];
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $probe, fn () => 'v20');

        $this->assertFalse($r['daemons'][0]['reachable']);
        $this->assertStringContainsString('Non-JSON', $r['daemons'][0]['error']);
        $this->assertFalse($r['daemons'][1]['reachable']);
        $this->assertSame('HTTP 502 from /health', $r['daemons'][1]['error']);
    }

    public function testDisabledDaemonIsNotProbedAndWarns(): void
    {
        $calls = [];
        $probe = function (string $url) use (&$calls): array {
            $calls[] = $url;

            return ['status' => 200, 'body' => '{"status":"ok"}'];
        };
        $r = McpHealth::report($this->config(['system_daemon' => ['enabled' => 'false']]), self::ORIGIN, self::ORIGIN, $probe, fn () => 'v20');

        $this->assertSame(['http://127.0.0.1:8006/health'], $calls);
        $this->assertFalse($r['daemons'][1]['enabled']);
        $c = $this->check($r, 'daemon.system_mcp');
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('MCP_SYSTEM_DAEMON_ENABLED', $c['message']);
        $this->assertSame('warn', $r['status']);
    }

    public function testAppUrlMismatchWarnsAboutOAuthLoop(): void
    {
        $r = McpHealth::report($this->config(), 'http://localhost', self::ORIGIN, $this->upProbe(), fn () => 'v20');

        $c = $this->check($r, 'app_url');
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('OAuth', $c['message']);
        $this->assertSame('http://localhost', $c['details']['app_url']);
        $this->assertSame(self::ORIGIN, $c['details']['request_origin']);
        $this->assertSame('warn', $r['status']);
    }

    public function testAppUrlComparisonIgnoresTrailingSlashCaseAndDefaultPort(): void
    {
        $r = McpHealth::report($this->config(), 'HTTPS://DF.example.com:443/', self::ORIGIN, $this->upProbe(), fn () => 'v20');
        $this->assertSame('ok', $this->check($r, 'app_url')['status']);

        $r = McpHealth::report($this->config(), '', self::ORIGIN, $this->upProbe(), fn () => 'v20');
        $this->assertSame('warn', $this->check($r, 'app_url')['status']);
        $this->assertStringContainsString('not set', $this->check($r, 'app_url')['message']);
    }

    /** TLS terminated at nginx/ALB with no trusted-proxy config: PHP sees http://, the client used https://. */
    public function testForwardedHttpsBehindProxyMatchesHttpsAppUrl(): void
    {
        $fwd = McpHealth::forwardedOrigin('https', 'df.example.com', 'http://df.example.com');
        $this->assertSame('https://df.example.com', $fwd);

        $r = McpHealth::report($this->config(), self::ORIGIN, 'http://df.example.com', $this->upProbe(), fn () => 'v20', $fwd);

        $c = $this->check($r, 'app_url');
        $this->assertSame('ok', $c['status']);
        $this->assertSame('http://df.example.com', $c['details']['request_origin']);
        $this->assertSame('https://df.example.com', $c['details']['forwarded_origin']);
        $this->assertNull($this->findCheck($r, 'app_url_scheme'));
        $this->assertSame('ok', $r['status']);
    }

    public function testSchemeOnlyMismatchWithoutForwardedHeadersIsASoftNote(): void
    {
        $r = McpHealth::report($this->config(), self::ORIGIN, 'http://df.example.com', $this->upProbe(), fn () => 'v20');

        $this->assertSame('ok', $this->check($r, 'app_url')['status']);
        $note = $this->check($r, 'app_url_scheme');
        $this->assertSame('ok', $note['status']);
        $this->assertStringContainsString('TLS', $note['message']);
        $this->assertStringContainsString('trusted proxies', $note['message']);
        $this->assertNull($note['details']['forwarded_origin']);
        $this->assertSame('ok', $r['status']);
    }

    public function testGenuineHostMismatchWarnsEvenWithForwardedHeaders(): void
    {
        $fwd = McpHealth::forwardedOrigin('https', 'api.internal.example.com', 'http://10.0.0.5');
        $r = McpHealth::report($this->config(), self::ORIGIN, 'http://10.0.0.5', $this->upProbe(), fn () => 'v20', $fwd);

        $c = $this->check($r, 'app_url');
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('https://api.internal.example.com', $c['message']);
        $this->assertSame('warn', $r['status']);

        // non-default port is part of the identity
        $r = McpHealth::report($this->config(), 'https://df.example.com:8443', 'http://df.example.com', $this->upProbe(), fn () => 'v20', 'https://df.example.com');
        $this->assertSame('warn', $this->check($r, 'app_url')['status']);
    }

    public function testForwardedOriginParsing(): void
    {
        $this->assertNull(McpHealth::forwardedOrigin(null, null, 'http://h'));
        $this->assertNull(McpHealth::forwardedOrigin('', '', 'http://h'));
        // first value of a comma list wins; missing half comes from the raw origin
        $this->assertSame('https://h:8081', McpHealth::forwardedOrigin('https, http', null, 'http://h:8081'));
        $this->assertSame('http://pub.example.com', McpHealth::forwardedOrigin(null, 'pub.example.com, inner', 'http://h'));
        // garbage proto falls back to the raw scheme
        $this->assertSame('http://pub.example.com', McpHealth::forwardedOrigin('ftp', 'PUB.example.com', 'http://h'));
    }

    private function findCheck(array $report, string $id): ?array
    {
        foreach ($report['checks'] as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return null;
    }

    public function testRemoteDaemonWithoutInternalKeyOrBaseUrlWarns(): void
    {
        $cfg = $this->config(['daemon' => ['url' => 'http://mcp-daemon:8006']]);
        $r = McpHealth::report($cfg, self::ORIGIN, self::ORIGIN, $this->upProbe(), fn () => null);

        // A generated key file counts as configured, but a remote daemon cannot read it.
        $key = $this->check($r, 'internal_key');
        $this->assertSame('warn', $key['status']);
        $this->assertTrue($key['details']['internal_key_set']);
        $this->assertSame('file', $key['details']['source']);
        $this->assertStringContainsString('MCP_INTERNAL_KEY', $key['message']);
        $this->assertSame('warn', $this->check($r, 'internal_base_url')['status']);
        // node missing is only a warning when the daemon lives elsewhere
        $this->assertSame('warn', $this->check($r, 'node')['status']);

        $cfg['daemon']['internal_key'] = 's3cret';
        $cfg['daemon']['internal_base_url'] = 'http://web';
        $r = McpHealth::report($cfg, self::ORIGIN, self::ORIGIN, $this->upProbe(), fn () => null);
        $this->assertSame('ok', $this->check($r, 'internal_key')['status']);
        $this->assertSame('ok', $this->check($r, 'internal_base_url')['status']);
    }

    public function testLoopbackDaemonsWithGeneratedKeyFileIsOk(): void
    {
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $this->upProbe(), fn () => 'v20');
        $key = $this->check($r, 'internal_key');
        $this->assertSame('ok', $key['status']);
        $this->assertTrue($key['details']['internal_key_set']);
        $this->assertSame('file', $key['details']['source']);
        $this->assertStringContainsString('storage/framework/mcp_internal_key', $key['message']);
        $this->assertSame('ok', $this->check($r, 'internal_base_url')['status']);
    }

    public function testExplicitInternalKeyIsOk(): void
    {
        $r = McpHealth::report($this->config(['daemon' => ['internal_key' => 's3cret']]), self::ORIGIN, self::ORIGIN, $this->upProbe(), fn () => 'v20');
        $key = $this->check($r, 'internal_key');
        $this->assertSame('ok', $key['status']);
        $this->assertSame('env', $key['details']['source']);
    }

    public function testNoKeyAtAllIsAnErrorBecauseTheDaemonFailsClosed(): void
    {
        $r = McpHealth::report($this->config(['daemon' => ['internal_key_resolved' => false]]), self::ORIGIN, self::ORIGIN, $this->upProbe(), fn () => 'v20');
        $key = $this->check($r, 'internal_key');
        $this->assertSame('error', $key['status']);
        $this->assertFalse($key['details']['internal_key_set']);
        $this->assertStringContainsString('storage/framework', $key['message']);
        $this->assertSame('error', $r['status']);
    }

    /** Data daemon /health reporting function_tools, plus a configured function-tool count. */
    private function functionToolsCheck(?bool $enabled, ?int $configured): array
    {
        $probe = function (string $url) use ($enabled): array {
            $body = ['status' => 'ok', 'version' => '1.0.0', 'mode' => 'stateless'];
            if ($enabled !== null && !str_contains($url, ':3700')) {
                $body['function_tools'] = $enabled;
            }

            return ['status' => 200, 'body' => json_encode($body)];
        };

        return $this->check(McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $probe, fn () => 'v20', null, $configured), 'function_tools');
    }

    public function testFunctionToolsCheck(): void
    {
        $c = $this->functionToolsCheck(false, 2);
        $this->assertSame('warn', $c['status']);
        $this->assertStringContainsString('2 function tools are configured but not offered', $c['message']);
        $this->assertStringContainsString('MCP_ALLOW_FUNCTION_TOOLS=true', $c['message']);
        $this->assertSame(['enabled' => false, 'configured' => 2], $c['details']);

        $this->assertSame('ok', $this->functionToolsCheck(false, 0)['status']);
        $this->assertSame('ok', $this->functionToolsCheck(false, null)['status']);
        $on = $this->functionToolsCheck(true, 2);
        $this->assertSame('ok', $on['status']);
        $this->assertStringContainsString('enabled', $on['message']);
        // Older daemon that does not report the flag: unknown, never a warning.
        $old = $this->functionToolsCheck(null, 2);
        $this->assertSame('ok', $old['status']);
        $this->assertNull($old['details']['enabled']);
    }

    public function testNodeMissingWithLocalDeadDaemonIsTheHeadlineError(): void
    {
        $probe = function (): array {
            throw new \RuntimeException('Connection refused');
        };
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $probe, fn () => null);

        $c = $this->check($r, 'node');
        $this->assertSame('error', $c['status']);
        $this->assertStringContainsString('Install Node', $c['message']);
        $this->assertNull($this->check($r, 'stateless')['details']['stateless']);
    }

    public function testNodeLookupNotPermittedIsSkippedNotFailed(): void
    {
        $node = function (): ?string {
            throw new \RuntimeException('proc_open disabled');
        };
        $r = McpHealth::report($this->config(), self::ORIGIN, self::ORIGIN, $this->upProbe(), $node);

        $c = $this->check($r, 'node');
        $this->assertSame('ok', $c['status']);
        $this->assertTrue($c['details']['skipped']);
        $this->assertSame('ok', $r['status']);
    }

    public function testOverallStatusIsWorst(): void
    {
        // error (data daemon down) beats warn (APP_URL mismatch)
        $probe = fn (string $url) => str_contains($url, ':3700')
            ? ['status' => 200, 'body' => '{"status":"healthy"}']
            : throw new \RuntimeException('refused');
        $r = McpHealth::report($this->config(), 'http://other', self::ORIGIN, $probe, fn () => 'v20');
        $this->assertSame('error', $r['status']);
    }

    public function testIsLoopback(): void
    {
        $this->assertTrue(McpHealth::isLoopback('http://127.0.0.1:8006'));
        $this->assertTrue(McpHealth::isLoopback('http://127.5.5.5'));
        $this->assertTrue(McpHealth::isLoopback('http://LOCALHOST:3700'));
        $this->assertTrue(McpHealth::isLoopback('http://[::1]:3700'));
        $this->assertFalse(McpHealth::isLoopback('http://web:8006'));
        $this->assertFalse(McpHealth::isLoopback('http://10.0.0.5:8006'));
    }
}
