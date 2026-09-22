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
     * @param string          $requestOrigin scheme://host[:port] as PHP saw the request
     * @param callable        $probe         fn(string $healthUrl): array{status:int, body:string}; may throw
     * @param callable        $nodeVersion   fn(): ?string  version string, null when node is missing;
     *                                       throws when shelling out is not permitted
     * @param string|null     $forwardedOrigin origin from X-Forwarded-Proto/-Host (see forwardedOrigin()),
     *                                       null when the request carried neither header
     * @param int|null        $functionTools enabled function custom tools configured; null when unknown
     *
     * $mcpConfig['daemon'] may also carry internal_key_resolved (bool: a key, from
     * MCP_INTERNAL_KEY or the generated file, is being sent) and internal_key_file.
     */
    public static function report(array $mcpConfig, ?string $appUrl, string $requestOrigin, callable $probe, callable $nodeVersion, ?string $forwardedOrigin = null, ?int $functionTools = null): array
    {
        $daemons = [
            self::probeDaemon(DaemonTarget::forServiceType(McpServiceTypes::DATA, $mcpConfig), $probe),
            self::probeDaemon(DaemonTarget::forServiceType(McpServiceTypes::SYSTEM, $mcpConfig), $probe),
        ];

        $checks = [];
        foreach ($daemons as $d) {
            $checks[] = self::daemonCheck($d);
        }
        array_push($checks, ...self::appUrlChecks($appUrl, $requestOrigin, $forwardedOrigin));
        $checks[] = self::internalBaseUrlCheck($mcpConfig, $daemons);
        $checks[] = self::internalKeyCheck($mcpConfig, $daemons);
        $checks[] = self::nodeCheck($nodeVersion, $daemons[0]);
        $checks[] = self::statelessCheck($daemons[0]);
        $checks[] = self::functionToolsCheck($daemons[0], $functionTools);

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
            'function_tools' => null,
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
            $record['function_tools'] = is_bool($body['function_tools'] ?? null) ? $body['function_tools'] : null;
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

    /**
     * APP_URL vs where the request came from. Host and port decide `app_url`
     * (a wrong host or port loops OAuth). A scheme-only difference is the
     * normal shape of TLS terminated at a proxy with no trusted-proxy config,
     * so it is a separate `app_url_scheme` note, not a warning. Forwarded
     * headers, when present, are used for the comparison (diagnostic only).
     *
     * @return array[] one or two checks
     */
    private static function appUrlChecks(?string $appUrl, string $requestOrigin, ?string $forwardedOrigin): array
    {
        $details = ['app_url' => $appUrl, 'request_origin' => $requestOrigin, 'forwarded_origin' => $forwardedOrigin];
        $configured = self::originParts((string) $appUrl);
        $seen = $forwardedOrigin ?? $requestOrigin;
        if ($configured === null) {
            return [self::check('app_url', 'warn', 'APP_URL is not set. OAuth clients are redirected to APP_URL, so set it to the address you use in the browser (' . $seen . ').', $details)];
        }
        $effective = self::originParts($seen);
        $hostMatch = $effective !== null
            && $effective['host'] === $configured['host']
            && ($effective['port'] === $configured['port'] || ($effective['default_port'] && $configured['default_port']));
        if (!$hostMatch) {
            return [self::check('app_url', 'warn', "APP_URL ({$appUrl}) does not match the address this request came from ({$seen}). OAuth redirects go to APP_URL, so MCP clients will loop or fail to log in. Set APP_URL to the public address and run php artisan config:clear.", $details)];
        }

        $checks = [self::check('app_url', 'ok', "APP_URL matches the request host ({$seen}).", $details)];
        if ($effective['scheme'] !== $configured['scheme']) {
            $checks[] = self::check('app_url_scheme', 'ok', "APP_URL is {$configured['scheme']} but PHP saw this request as {$effective['scheme']} on the same host. This is expected when TLS terminates at a reverse proxy or load balancer (nginx, ALB); OAuth uses APP_URL, so it still works. If clients still loop, make sure the proxy sends X-Forwarded-Proto and that DreamFactory trusts it (trusted proxies).", $details);
        }

        return $checks;
    }

    /**
     * Origin implied by X-Forwarded-Proto / X-Forwarded-Host, or null when the
     * request carried neither. Only the first value of each list is used; the
     * missing half comes from the raw origin. Diagnostic only, never for auth.
     */
    public static function forwardedOrigin(?string $forwardedProto, ?string $forwardedHost, string $requestOrigin): ?string
    {
        $proto = strtolower(trim(explode(',', (string) $forwardedProto)[0]));
        $host = strtolower(trim(explode(',', (string) $forwardedHost)[0]));
        if ($proto === '' && $host === '') {
            return null;
        }
        $raw = self::originParts($requestOrigin);
        $scheme = in_array($proto, ['http', 'https'], true) ? $proto : ($raw['scheme'] ?? 'http');
        if ($host === '') {
            $host = $raw['host'] ?? '';
            if ($raw && !$raw['default_port']) {
                $host .= ':' . $raw['port'];
            }
        }

        return $scheme . '://' . $host;
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
        $env = $mcpConfig['daemon']['internal_key'] ?? null;
        $env = is_string($env) && $env !== '';
        $file = $mcpConfig['daemon']['internal_key_file'] ?? null;
        // A generated key file counts as configured.
        $set = $env || !empty($mcpConfig['daemon']['internal_key_resolved']);
        $details = ['internal_key_set' => $set, 'source' => $env ? 'env' : ($set ? 'file' : null), 'internal_key_file' => $env ? null : $file];
        if (!$set) {
            return self::check('internal_key', 'error', 'No shared key: MCP_INTERNAL_KEY is unset and DreamFactory could not write ' . ($file ?: 'storage/framework/mcp_internal_key') . '. The data daemon rejects every MCP call without it. Make storage/framework writable by the web server, or set the same MCP_INTERNAL_KEY in DreamFactory and on the daemons.', $details);
        }
        $remote = self::remoteDaemons($daemons);
        if (!$env && $remote) {
            return self::check('internal_key', 'warn', 'Using the key DreamFactory generated at ' . $file . ', but ' . implode(' and ', $remote) . ' runs on another host and cannot read that file unless it is shared. The data daemon rejects every call without the key, and the system daemon only checks it when MCP_INTERNAL_KEY is set on its side. Set the same MCP_INTERNAL_KEY in DreamFactory and on the daemons.', $details);
        }

        return self::check('internal_key', 'ok', $env
            ? 'Daemon calls carry X-Mcp-Internal-Key (MCP_INTERNAL_KEY).'
            : "Daemon calls carry X-Mcp-Internal-Key, generated at {$file}. The daemon reads the same file; its user must be able to read storage/framework.", $details);
    }

    /** Function custom tools run only when the data daemon has MCP_ALLOW_FUNCTION_TOOLS=true. */
    private static function functionToolsCheck(array $dataDaemon, ?int $functionTools): array
    {
        $enabled = $dataDaemon['function_tools'];
        $details = ['enabled' => $enabled, 'configured' => $functionTools];
        if ($enabled === null) {
            return self::check('function_tools', 'ok', 'Unknown whether the data daemon runs function tools (not reachable, or a version that does not report it).', $details);
        }
        if ($enabled) {
            return self::check('function_tools', 'ok', 'Function tools are enabled (MCP_ALLOW_FUNCTION_TOOLS=true): admin-authored JavaScript runs inside the data daemon.', $details);
        }
        if ($functionTools) {
            return self::check('function_tools', 'warn', "{$functionTools} function tool" . ($functionTools === 1 ? ' is' : 's are') . ' configured but not offered to clients: the data daemon runs function tools only when MCP_ALLOW_FUNCTION_TOOLS=true is set in its environment. Set it and restart the daemon if you trust every admin who can edit them.', $details);
        }

        return self::check('function_tools', 'ok', 'Function tools are disabled (default). Set MCP_ALLOW_FUNCTION_TOOLS=true on the data daemon to run them.', $details);
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

    /**
     * @return array{scheme:string, host:string, port:int, default_port:bool}|null null when unparseable
     */
    private static function originParts(string $url): ?array
    {
        $p = parse_url(trim($url));
        if (!is_array($p) || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme'] ?? 'http');
        $default = $scheme === 'https' ? 443 : 80;
        $port = (int) ($p['port'] ?? $default);

        return ['scheme' => $scheme, 'host' => strtolower($p['host']), 'port' => $port, 'default_port' => $port === $default];
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
