<?php

namespace DreamFactory\Core\McpServer\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP Client for communicating with MCP Daemon Server
 */
class McpDaemonClient
{
    private string $daemonUrl;

    private string $internalKey;

    public function __construct(?string $daemonUrl = null)
    {
        $this->daemonUrl = $daemonUrl ?? config('mcp.daemon.url', 'http://127.0.0.1:8006');
        // Shared secret the daemon requires on every request. Resolved once per
        // client so both the PHP side and the daemon converge on one key.
        $this->internalKey = $this->resolveInternalKey();
    }

    /**
     * Resolve the shared secret sent to the daemon on the X-Mcp-Internal-Key
     * header. An explicit MCP_INTERNAL_KEY wins. Otherwise the key is generated
     * once and persisted to a file on shared storage that the daemon also reads,
     * so both sides share a secret with no extra configuration and without
     * depending on APP_KEY reaching the daemon process environment.
     */
    private function resolveInternalKey(): string
    {
        $configured = (string) config('mcp.daemon.internal_key');
        if ($configured !== '') {
            return $configured;
        }

        $keyFile = (string) config('mcp.daemon.internal_key_file');
        if ($keyFile === '') {
            // Not storage/app: that directory is the root of the stock "files"
            // service, so a secret written there is downloadable over the REST
            // API by any caller with read access to it. storage/framework is
            // not served by any file service.
            $keyFile = storage_path('framework/mcp_internal_key');
        }

        try {
            $existing = $this->readKeyFile($keyFile);
            if ($existing !== '') {
                return $existing;
            }

            $key = bin2hex(random_bytes(32));
            $dir = dirname($keyFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }

            // Write to a temp file then rename into place so a concurrent
            // request and the daemon never read a partially written file.
            $tmp = $keyFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $key, LOCK_EX) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $keyFile);
                @unlink($tmp);
            }

            // Re-read so concurrent first-request workers converge on the one
            // key that actually landed on disk.
            $winner = $this->readKeyFile($keyFile);

            return $winner !== '' ? $winner : $key;
        } catch (\Throwable $e) {
            Log::warning('MCP internal key provisioning failed', ['error' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * The shared secret this instance uses on the PHP proxy -> daemon hop.
     * Empty when it could not be resolved or provisioned.
     */
    public function getInternalKey(): string
    {
        return $this->internalKey;
    }

    private function readKeyFile(string $keyFile): string
    {
        if (is_file($keyFile)) {
            return trim((string) @file_get_contents($keyFile));
        }

        return '';
    }

    /**
     * Proxy request to daemon server
     *
     * @param array $availableServices Pre-resolved list of available services (bypasses RBAC)
     */
    public function proxyRequest(Request $request, string $mcpService, array $config, string $baseUrl, string $dfSessionToken, array $availableServices = []): Response|JsonResponse|StreamedResponse
    {
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 300,
            ]);

            $headers = [
                'X-Mcp-Base-Url' => $baseUrl,
                'X-DreamFactory-Session-Token' => $dfSessionToken,
                // Shared secret so the daemon can confirm this call came from the
                // PHP proxy (post-RBAC) and not another local caller.
                'X-Mcp-Internal-Key' => $this->internalKey,
                'Accept' => 'application/json, text/event-stream',
                // Platform trace id: the daemon re-attaches this to its DF REST
                // sub-calls so all rows of one MCP action join on one id.
                \DreamFactory\Core\Utility\TraceId::HEADER => \DreamFactory\Core\Utility\TraceId::get(),
            ];

            // Pass API key if configured (required for non-admin users)
            $appId = $config['app_id'] ?? null;
            Log::debug('MCP API Key lookup', ['app_id' => $appId, 'config_keys' => array_keys($config)]);
            if ($appId) {
                $apiKey = \DreamFactory\Core\Models\App::getApiKeyByAppId($appId);
                Log::debug('MCP API Key result', ['app_id' => $appId, 'api_key_found' => !empty($apiKey)]);
                if ($apiKey) {
                    $headers['X-DreamFactory-API-Key'] = $apiKey;
                }
            }

            // Copy relevant headers from original request
            foreach ($request->headers->all() as $key => $values) {
                $lowerKey = strtolower($key);
                if (in_array($lowerKey, ['content-type', 'mcp-session-id', 'last-event-id'])) {
                    $headers[$key] = $values[0] ?? '';
                }
            }

            // Build the request body: merge the original MCP JSON-RPC payload with
            // config metadata.  For POST initialisation requests the original body is
            // a JSON-RPC object; we wrap it so the daemon can extract both the MCP
            // payload and the DreamFactory-specific metadata from the body instead of
            // oversized HTTP headers (Node.js enforces a 16 KB total-header limit).
            $originalBody = $request->getContent();
            $body = $originalBody;

            // Only wrap the body for POST requests (MCP protocol init / tool calls).
            // GET/DELETE requests don't carry a JSON body.
            if ($request->method() === 'POST') {
                // Use json_decode WITHOUT assoc flag to preserve empty objects ({} vs [])
                // PHP's json_decode($str, true) converts {} to [] which breaks JSON-RPC schemas
                $mcpPayload = json_decode($originalBody);
                $envelope = (object)[
                    '_mcpPayload' => $mcpPayload,
                    '_mcpConfig' => $config,
                    '_mcpAvailableServices' => $availableServices ?: [],
                ];
                $body = json_encode($envelope);
                // Override Content-Type since we're wrapping the payload
                $headers['content-type'] = 'application/json';
            } else {
                // For non-POST requests, pass config via headers (these are smaller
                // requests like session resumption that don't carry disabled_tools).
                $headers['X-Mcp-Config'] = json_encode($config);
                if (!empty($availableServices)) {
                    $headers['X-Mcp-Available-Services'] = json_encode($availableServices);
                }
            }

            $daemonPath = "/mcp/{$mcpService}";
            $response = $client->request($request->method(), $this->daemonUrl . $daemonPath, [
                'headers' => $headers,
                'body' => $body,
                'expect' => false,
            ]);

            $status = $response->getStatusCode();
            $contentType = $response->getHeaderLine('Content-Type') ?: 'application/json';

            if (str_contains(strtolower($contentType), 'text/event-stream')) {
                return $this->streamSseResponse($response, $status, $contentType);
            }

            return $this->buildJsonResponse($response, $status, $contentType);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $body = (string) $e->getResponse()?->getBody();
            Log::error('MCP daemon client error', [
                'mcpService' => $mcpService,
                'status' => $e->getResponse()?->getStatusCode(),
                'body' => $body,
            ]);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32000,
                    'message' => 'Client error from daemon',
                    'data' => $body ? json_decode($body, true) : null,
                ],
            ], $e->getResponse()?->getStatusCode() ?? 400);

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Failed to connect to MCP daemon', [
                'daemonUrl' => $this->daemonUrl,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'MCP daemon is not running. Please start it with: php artisan mcp:daemon',
            ], 503);

        } catch (\GuzzleHttp\Exception\ServerException $e) {
            $body = (string) $e->getResponse()?->getBody();
            Log::error('MCP daemon server error', [
                'mcpService' => $mcpService,
                'status' => $e->getResponse()?->getStatusCode(),
                'body' => $body,
            ]);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32000,
                    'message' => 'Daemon server error',
                    'data' => $body ? json_decode($body, true) : null,
                ],
            ], 500);

        } catch (\Throwable $e) {
            Log::error('MCP daemon proxy error', [
                'mcpService' => $mcpService,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32000,
                    'message' => 'Server error: ' . $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Run a complete MCP exchange for a first-party caller that speaks bare
     * JSON-RPC with no session handshake of its own (e.g. df-ai-chat's
     * McpToolClient). The StreamableHTTP transport is session-stateful, so we
     * perform initialize + notifications/initialized and then the caller's
     * actual request under one throwaway session, returning the final
     * JSON-RPC response.
     *
     * ponytail: opens a fresh MCP session per call. Fine for chat's
     * list-then-call cadence; cache the session id per (service,dfSessionToken)
     * if a hot tool loop ever makes the two extra round-trips matter.
     *
     * @param array $jsonRpc The caller's JSON-RPC request (method/params/id).
     * @return array Decoded JSON-RPC response object from the daemon.
     */
    public function rpcStateless(
        string $mcpService,
        array $config,
        string $baseUrl,
        string $dfSessionToken,
        array $availableServices,
        array $jsonRpc
    ): array {
        $client = new \GuzzleHttp\Client(['timeout' => 300]);
        $url = $this->daemonUrl . "/mcp/{$mcpService}";

        $headers = [
            'X-Mcp-Base-Url'               => $baseUrl,
            'X-DreamFactory-Session-Token' => $dfSessionToken,
            'X-Mcp-Internal-Key'           => $this->internalKey,
            'Content-Type'                 => 'application/json',
            'Accept'                       => 'application/json, text/event-stream',
        ];
        if ($appId = ($config['app_id'] ?? null)) {
            if ($apiKey = \DreamFactory\Core\Models\App::getApiKeyByAppId($appId)) {
                $headers['X-DreamFactory-API-Key'] = $apiKey;
            }
        }

        // Standard MCP handshake: initialize -> notifications/initialized ->
        // the caller's real request, threading the minted session id.
        //
        // NOTE (blocked): the daemon's StreamableHTTP transport runs with
        // enableJsonResponse and tears the session down (transport.onclose ->
        // sessions.delete) right after each POST response, so the id captured
        // from initialize is already gone by the follow-up POST ("Server not
        // initialized"). Batching initialize with the call is also rejected
        // ("Only one initialization request is allowed"). This client is
        // therefore correct but cannot complete until the daemon keeps a
        // JSON-mode session alive across POSTs (short TTL) or exposes an
        // internal one-shot endpoint. See handoff.md.
        //
        // Empty params must serialize as {} not [], or the transport rejects
        // the message as invalid JSON-RPC.
        $jsonRpc = self::restoreEmptyJsonObjects($jsonRpc);

        $envelope = fn (array $payload) => json_encode([
            '_mcpPayload'           => $payload,
            '_mcpConfig'            => $config,
            '_mcpAvailableServices' => $availableServices ?: [],
        ]);
        // $headers is captured by reference — the Mcp-Session-Id added after
        // initialize must be sent on the follow-up POSTs.
        $post = function (array $payload) use ($client, $url, &$headers, $envelope) {
            return $client->post($url, [
                'headers'     => $headers,
                'body'        => $envelope($payload),
                'expect'      => false,
                'http_errors' => false,
            ]);
        };

        $init = $post([
            'jsonrpc' => '2.0',
            'id'      => 'init',
            'method'  => 'initialize',
            'params'  => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => (object) [],
                'clientInfo'      => ['name' => 'df-ai-chat', 'version' => '1.0'],
            ],
        ]);
        $sessionId = $init->getHeaderLine('Mcp-Session-Id');
        if ($sessionId !== '') {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        $post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $resp = $post($jsonRpc);

        return $this->decodeDaemonBody((string) $resp->getBody());
    }

    /**
     * Decode a daemon response body that is either a plain JSON-RPC object or
     * an SSE frame ("event: message\ndata: {...}"). Returns the last JSON-RPC
     * object found, or an empty array.
     */
    /**
     * json_decode(..., true) upstream (Mcp::handleRpcBridge) collapses empty
     * JSON objects to PHP empty arrays, which re-serialize as [] — rejected by
     * the daemon's JSON-RPC / zod validation. Restore {} where the MCP schema
     * requires an object: top-level `params`, and `params.arguments` for an
     * argument-less tools/call.
     */
    public static function restoreEmptyJsonObjects(array $jsonRpc): array
    {
        if (($jsonRpc['params'] ?? null) === []) {
            $jsonRpc['params'] = (object) [];
        }
        if (is_array($jsonRpc['params'] ?? null) && (($jsonRpc['params']['arguments'] ?? null) === [])) {
            $jsonRpc['params']['arguments'] = (object) [];
        }

        return $jsonRpc;
    }

    private function decodeDaemonBody(string $body): array
    {
        $trimmed = ltrim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? $decoded : [];
        }

        $result = [];
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                $decoded = json_decode(trim(substr($line, 5)), true);
                if (is_array($decoded)) {
                    $result = $decoded;
                }
            }
        }
        return $result;
    }

    private function streamSseResponse($response, int $status, string $contentType)
    {
        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];

        // Copy headers from daemon response
        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        // Do NOT call ignore_user_abort(true) — we want PHP to detect when the
        // client disconnects so the worker is freed immediately.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // Hard cap: 5 minutes max for any SSE stream
        }

        return response()->stream(function () use ($response) {
            $body = $response->getBody();
            while (!$body->eof()) {
                // Check if the client has disconnected — frees the PHP-FPM worker
                // instead of holding it until the daemon closes the connection.
                if (connection_aborted()) {
                    break;
                }
                echo $body->read(8192);
                if (function_exists('ob_flush')) @ob_flush();
                if (function_exists('flush')) @flush();
            }
        }, $status, $headers);
    }

    private function buildJsonResponse($response, int $status, string $contentType)
    {
        $body = (string) $response->getBody();
        $resp = response($body, $status)
            ->header('Content-Type', $contentType);

        // Copy headers from daemon response
        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            $resp->header($name, implode(', ', $values));
        }

        return $resp;
    }
}
