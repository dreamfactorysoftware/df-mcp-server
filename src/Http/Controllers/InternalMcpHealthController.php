<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Support\McpHealth;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

/**
 * Admin-only GET /_internal/ai/mcp-health: are the MCP daemons up and is the
 * install sane (APP_URL, node, internal key)? Never throws; the report says
 * what is wrong and what to do. Logic lives in Support\McpHealth so it can be
 * unit-tested with fake probes.
 */
class InternalMcpHealthController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        if (!Session::isSysAdmin()) {
            return response()->json(['error' => ['message' => 'Admin access required.']], 403);
        }

        $origin = $request->getSchemeAndHttpHost();
        $report = McpHealth::report(
            (array) config('mcp', []),
            config('app.url'),
            $origin,
            [self::class, 'probe'],
            [self::class, 'nodeVersion'],
            // Diagnostic only: behind a TLS-terminating proxy PHP sees http://
            // while APP_URL is https://; the forwarded headers say what the
            // client used. Read raw (not via Symfony's trusted-proxy logic) on purpose.
            McpHealth::forwardedOrigin($request->headers->get('X-Forwarded-Proto'), $request->headers->get('X-Forwarded-Host'), $origin)
        );

        return response()->json($report);
    }

    /** @return array{status:int, body:string} */
    public static function probe(string $url): array
    {
        $headers = ['Accept' => 'application/json'];
        $key = config('mcp.daemon.internal_key');
        if (is_string($key) && $key !== '') {
            $headers['X-Mcp-Internal-Key'] = $key;
        }
        $res = (new \GuzzleHttp\Client([
            'timeout'         => McpHealth::DAEMON_TIMEOUT_SECONDS,
            'connect_timeout' => McpHealth::DAEMON_TIMEOUT_SECONDS,
            'http_errors'     => false,
        ]))->get($url, ['headers' => $headers]);

        return ['status' => $res->getStatusCode(), 'body' => (string) $res->getBody()];
    }

    /** `node --version` on this host; null when missing, throws when shelling out is not permitted. */
    public static function nodeVersion(): ?string
    {
        $p = new Process(['node', '--version']);
        $p->setTimeout(McpHealth::DAEMON_TIMEOUT_SECONDS);
        try {
            $p->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            return null;
        }
        $out = trim($p->getOutput());

        return $p->isSuccessful() && $out !== '' ? $out : null;
    }
}
