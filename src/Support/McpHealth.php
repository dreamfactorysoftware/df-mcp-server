<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Support;

use DreamFactory\Core\McpServer\Enums\McpServiceTypes;

/**
 * Builds the admin "is MCP actually set up?" report served by
 * GET /_internal/ai/mcp-health. Framework-free: every I/O (daemon HTTP probe,
 * node binary lookup) is injected so it runs in a standalone PHPUnit checkout.
 *
 * Report shape:
 *   status   'ok'|'warn'|'error'   worst of all checks
 *   daemons  one record per DaemonTarget (data + system)
 *   checks   [{ id, status, message, details }]
 */
final class McpHealth
{
    public const DAEMON_TIMEOUT_SECONDS = 2;

    /**
     * @param array           $mcpConfig     config('mcp')
     * @param string|null     $appUrl        config('app.url')
     * @param string          $requestOrigin scheme://host[:port] of the incoming request
     * @param callable        $probe         fn(string $healthUrl): array{status:int, body:string}; may throw
     * @param callable        $nodeVersion   fn(): ?string  version string, null when node is missing;
     *                                       throws when shelling out is not permitted
     */
    public static function report(array $mcpConfig, ?string $appUrl, string $requestOrigin, callable $probe, callable $nodeVersion): array
    {
        $daemons = [
            self::probeDaemon(DaemonTarget::forServiceType(McpServiceTypes::DATA, $mcpConfig), $probe),
            self::probeDaemon(DaemonTarget::forServiceType(McpServiceTypes::SYSTEM, $mcpConfig), $probe),
        ];

        $checks = [];
        foreach ($daemons as $d) {
            $checks[] = self::daemonCheck($d);
        }
        $checks[] = self::appUrlCheck($appUrl, $requestOrigin);
        $checks[] = self::internalBaseUrlCheck($mcpConfig, $daemons);
        $checks[] = self::internalKeyCheck($mcpConfig, $daemons);
        $checks[] = self::nodeCheck($nodeVersion, $daemons[0]);
        $checks[] = self::statelessCheck($daemons[0]);

        return [
            'status'  => self::worst(array_column($checks, 'status')),
            'daemons' => $daemons,
            'checks'  => $checks,
        ];
    }

    private static function probeDaemon(array $target, callable $probe): array
    {
        $record = [
            'type'       => $target['type'],
            'label'      => $target['label'],
            'enabled'    => $target['enabled'],
            'url'        => $target['url'],
            'reachable'  => false,
            'latency_ms' => null,
            'version'    => null,
            'mode'       => null,
            'tools'      => null,
            'error'      => null,
        ];
        if (!$target['enabled']) {
            return $record;
        }

        $start = microtime(true);
        try {
            $res = $probe($target['url'] . '/health');
            $record['latency_ms'] = (int) round((microtime(true) - $start) * 1000);
            $status = (int) ($res['status'] ?? 0);
            if ($status < 200 || $status >= 300) {
                $record['error'] = "HTTP {$status} from /health";

                return $record;
            }
            $body = json_decode((string) ($res['body'] ?? ''), true);
            if (!is_array($body)) {
                $record['error'] = 'Non-JSON response from /health (is something else listening on this port?)';

                return $record;
            }
            $record['reachable'] = true;
            $record['version'] = isset($body['version']) ? (string) $body['version'] : null;
            $record['mode'] = in_array($body['mode'] ?? null, ['stateful', 'stateless'], true) ? $body['mode'] : null;
            $record['tools'] = isset($body['tools']) && is_numeric($body['tools']) ? (int) $body['tools'] : null;
        } catch (\Throwable $e) {
            $record['latency_ms'] = (int) round((microtime(true) - $start) * 1000);
            $record['error'] = $e->getMessage();
        }

        return $record;
    }

    private static function daemonCheck(array $d): array
    {
        $id = 'daemon.' . $d['type'];
        $env = $d['type'] === McpServiceTypes::SYSTEM ? 'MCP_SYSTEM_DAEMON_URL' : 'MCP_DAEMON_URL';
        $start = $d['type'] === McpServiceTypes::SYSTEM
            ? 'scripts/start-system-daemon.sh (or its container)'
            : 'scripts/start-daemon.sh';

        if (!$d['enabled']) {
            $enabledEnv = $d['type'] === McpServiceTypes::SYSTEM ? 'MCP_SYSTEM_DAEMON_ENABLED' : 'MCP_DAEMON_ENABLED';
            $svc = $d['type'] === McpServiceTypes::SYSTEM ? 'system_mcp' : 'mcp';

            return self::check($id, 'warn', "{$d['label']} is disabled ({$enabledEnv}=false); {$svc} services answer 503. Set it to true if you use them.", $d);
        }
        if ($d['reachable']) {
            $ver = $d['version'] ? " v{$d['version']}" : '';

            return self::check($id, 'ok', "{$d['label']}{$ver} reachable at {$d['url']} ({$d['latency_ms']} ms).", $d);
        }

        return self::check(
            $id,
            'error',
            "{$d['label']} is not reachable at {$d['url']}: {$d['error']}. Start it ({$start}) or fix {$env} in .env, then run php artisan config:clear.",
            $d
        );
    }

    private static function appUrlCheck(?string $appUrl, string $requestOrigin): array
    {
        $details = ['app_url' => $appUrl, 'request_origin' => $requestOrigin];
        $configured = self::originOf((string) $appUrl);
        if ($configured === null) {
            return self::check('app_url', 'warn', 'APP_URL is not set. OAuth clients are redirected to APP_URL, so set it to the address you use in the browser (' . $requestOrigin . ').', $details);
        }
        if ($configured !== self::originOf($requestOrigin)) {
            return self::check('app_url', 'warn', "APP_URL ({$appUrl}) does not match the address this request came from ({$requestOrigin}). OAuth redirects go to APP_URL, so MCP clients will loop or fail to log in. Set APP_URL to the public address and run php artisan config:clear.", $details);
        }

        return self::check('app_url', 'ok', "APP_URL matches the request origin ({$requestOrigin}).", $details);
    }

    private static function internalBaseUrlCheck(array $mcpConfig, array $daemons): array
    {
        $base = $mcpConfig['daemon']['internal_base_url'] ?? null;
        $details = ['internal_base_url' => $base ?: null];
        $remote = self::remoteDaemons($daemons);
        if (!$base && $remote) {
            return self::check('internal_base_url', 'warn', 'MCP_INTERNAL_BASE_URL is not set but ' . implode(' and ', $remote) . ' runs on another host. The daemon calls DreamFactory back at the address of the incoming request, which must be reachable from the daemon host; set MCP_INTERNAL_BASE_URL (e.g. http://web) if it is not.', $details);
        }

        return self::check('internal_base_url', 'ok', $base ? "Daemons call DreamFactory back at {$base}." : 'Not set; daemons call DreamFactory back at the request origin (fine on a single host).', $details);
    }

    private static function internalKeyCheck(array $mcpConfig, array $daemons): array
    {
        $key = $mcpConfig['daemon']['internal_key'] ?? null;
        $set = is_string($key) && $key !== '';
        $details = ['internal_key_set' => $set];
        $remote = self::remoteDaemons($daemons);
        if (!$set && $remote) {
            return self::check('internal_key', 'warn', 'MCP_INTERNAL_KEY is not set but ' . implode(' and ', $remote) . ' listens on a non-loopback address. Anyone who can reach it can call it directly; set the same MCP_INTERNAL_KEY in DreamFactory and on the daemon.', $details);
        }

        return self::check('internal_key', 'ok', $set ? 'Daemon calls carry X-Mcp-Internal-Key.' : 'Not set; daemons are loopback-only so a shared secret is optional.', $details);
    }

    private static function nodeCheck(callable $nodeVersion, array $dataDaemon): array
    {
        try {
            $version = $nodeVersion();
        } catch (\Throwable $e) {
            return self::check('node', 'ok', 'Could not run node --version on this host (' . $e->getMessage() . '); skipped.', ['version' => null, 'skipped' => true]);
        }
        if ($version) {
            return self::check('node', 'ok', "Node {$version} found on this host.", ['version' => $version]);
        }
        $local = self::isLoopback($dataDaemon['url']);
        $status = $local && !$dataDaemon['reachable'] ? 'error' : 'warn';
        $hint = $local
            ? 'The MCP daemons are expected on this host, so they cannot start. Install Node 20+ (https://nodejs.org) and run the daemons, or point MCP_DAEMON_URL / MCP_SYSTEM_DAEMON_URL at a host that runs them.'
            : 'Fine if the daemons run on another host or in a container.';

        return self::check('node', $status, 'node is not installed or not on PATH for the web server user. ' . $hint, ['version' => null]);
    }

    private static function statelessCheck(array $dataDaemon): array
    {
        $mode = $dataDaemon['mode'];
        $details = ['mode' => $mode, 'stateless' => $mode === null ? null : $mode === 'stateless'];
        if ($mode === null) {
            return self::check('stateless', 'ok', 'Session mode unknown (data daemon not reachable).', $details);
        }

        return self::check('stateless', 'ok', $mode === 'stateless'
            ? 'Data daemon runs stateless (MCP_STATELESS=true): safe behind a load balancer.'
            : 'Data daemon runs stateful: sessions are pinned to this node. Set MCP_STATELESS=true on the daemon if you run several DreamFactory nodes behind a load balancer.', $details);
    }

    /** Labels of enabled daemons whose URL is not loopback. */
    private static function remoteDaemons(array $daemons): array
    {
        $out = [];
        foreach ($daemons as $d) {
            if ($d['enabled'] && !self::isLoopback($d['url'])) {
                $out[] = $d['label'];
            }
        }

        return $out;
    }

    public static function isLoopback(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = trim($host, '[]');

        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    /** scheme://host:port with default ports applied; null when unparseable. */
    private static function originOf(string $url): ?string
    {
        $p = parse_url(trim($url));
        if (!is_array($p) || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme'] ?? 'http');
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme . '://' . strtolower($p['host']) . ':' . $port;
    }

    private static function check(string $id, string $status, string $message, array $details): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message, 'details' => $details];
    }

    private static function worst(array $statuses): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'error' => 2];
        $max = 'ok';
        foreach ($statuses as $s) {
            if (($rank[$s] ?? 0) > $rank[$max]) {
                $max = $s;
            }
        }

        return $max;
    }
}
