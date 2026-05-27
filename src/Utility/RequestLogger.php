<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Utility;

use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Models\McpRequestLog;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records every MCP request that reaches a configured service. The audit row
 * powers the unified Gateway dashboard's "external client tool consumption"
 * panels — distinct from ai_usage_log (which tracks DF-paid AI provider
 * calls). MCP token cost is borne by the calling AI agent, NOT by DF.
 */
class RequestLogger
{
    public static function log(
        string $serviceName,
        Request $request,
        ?McpOAuthAccessToken $token,
        int $startNs,
        int $bytesOut,
        string $status,
        ?string $errorMessage = null,
    ): void {
        if (!config('mcp.audit_logging.enabled', true)) {
            return;
        }

        try {
            $serviceId = self::resolveServiceId($serviceName);
            if ($serviceId === null) {
                // Service was deleted or never existed — nothing to attribute the
                // row to (FK requires a live service). Skip silently.
                return;
            }

            $body = $request->getContent() ?: '';
            [$method, $toolName] = self::extractMethodAndTool($body);
            $durationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);

            McpRequestLog::create([
                'service_id'    => $serviceId,
                'user_id'       => $token?->user_id,
                'role_id'       => Session::getRoleId(),
                'app_id'        => Session::get('app.id'),
                'client_id'     => $token?->client_id,
                'client_name'   => self::resolveClientName($token),
                'method'        => $method,
                'tool_name'     => $toolName,
                'bytes_in'      => strlen($body),
                'bytes_out'     => $bytesOut,
                'duration_ms'   => $durationMs,
                'status'        => $status,
                'error_message' => $errorMessage,
                'request_id'    => (string) Str::uuid(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging must never break the MCP response path.
            Log::warning('Failed to log MCP request: ' . $e->getMessage());
        }
    }

    /** @var array<string, ?int> */
    private static array $serviceIdCache = [];

    private static function resolveServiceId(string $serviceName): ?int
    {
        if (!array_key_exists($serviceName, self::$serviceIdCache)) {
            $row = \DreamFactory\Core\Models\Service::where('name', $serviceName)->first(['id']);
            self::$serviceIdCache[$serviceName] = $row?->id;
        }
        return self::$serviceIdCache[$serviceName];
    }

    /**
     * Pull the JSON-RPC method and (for tools/call) the tool name out of the
     * request body. Returns ['(non-jsonrpc)', null] if the body isn't valid
     * JSON — keeps the row useful for connection establishment / SSE GETs.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function extractMethodAndTool(string $body): array
    {
        if ($body === '') {
            return ['connect', null];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['(non-jsonrpc)', null];
        }
        $method = (string) ($decoded['method'] ?? '(unknown)');
        $toolName = null;
        if ($method === 'tools/call' && isset($decoded['params']['name'])) {
            $toolName = (string) $decoded['params']['name'];
        }
        return [$method, $toolName];
    }

    /**
     * Best-effort human label for the connecting MCP client. The OAuth client
     * registration captured `client_name`; surface it so the dashboard can
     * say "Claude Desktop" instead of a UUID.
     */
    private static function resolveClientName(?McpOAuthAccessToken $token): ?string
    {
        if (!$token || empty($token->client_id)) {
            return null;
        }
        $client = \DreamFactory\Core\McpServer\Models\McpOAuthClient::where('client_id', $token->client_id)->first();
        return $client?->client_name;
    }
}
