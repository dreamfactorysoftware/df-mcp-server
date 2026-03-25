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

    public function __construct(?string $daemonUrl = null)
    {
        $this->daemonUrl = $daemonUrl ?? config('mcp.daemon.url', 'http://127.0.0.1:8006');
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
                'Accept' => 'application/json, text/event-stream',
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
                $envelope = [
                    '_mcpPayload' => json_decode($originalBody),
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

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        return response()->stream(function () use ($response) {
            $body = $response->getBody();
            while (!$body->eof()) {
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
