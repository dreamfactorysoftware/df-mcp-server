<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class McpStreamController extends Controller
{
    public function handleGet(Request $request, string $mcpService)
    {
        $accept = strtolower($request->header('Accept', ''));
        if (!str_contains($accept, 'text/event-stream')) {
            return response('Accept header must include text/event-stream for GET requests', 406);
        }

        return $this->processMcpRequest($request, $mcpService);
    }

    public function handlePost(Request $request, string $mcpService)
    {
        return $this->processMcpRequest($request, $mcpService);
    }

    private function processMcpRequest(Request $request, string $mcpService)
    {
        // Validate Bearer token and get DF session token
        $dfSessionToken = $this->validateBearerToken($request);
        if ($dfSessionToken instanceof \Illuminate\Http\JsonResponse) {
            return $dfSessionToken;
        }

        // Get service configuration from request (set by middleware)
        $config = $request->attributes->get('mcp_service_config');
        if (!$config) {
            return response()->json([
                'error' => 'MCP service not found',
                'service' => $mcpService,
            ], 404);
        }

        $apiName = $config['api_name'] ?? null;
        if (empty($apiName)) {
            Log::error('MCP service missing api_name', ['mcpService' => $mcpService]);
            return response()->json([
                'error' => 'MCP service misconfigured: api_name is required',
                'service' => $mcpService,
            ], 422);
        }

        // Determine scheme - prioritize X-Forwarded-Proto for proxies
        $scheme = $request->header('X-Forwarded-Proto');
        if (empty($scheme)) {
            $scheme = $request->getScheme();
        }

        // Force HTTPS if conditions are met
        $host = $request->getHttpHost();
        if ($scheme === 'https' || $request->secure() || str_starts_with($request->fullUrl(), 'https://')) {
            $scheme = 'https';
        } else {
            $scheme = 'http';
        }

        $baseUrl = $scheme . '://' . $host . '/api/v2/' . $apiName;

        if (!config('mcp.daemon.enabled', false)) {
            return response()->json([
                'error' => 'MCP daemon is disabled. Please set MCP_DAEMON_ENABLED=true and run the Node daemon.'
            ], 503);
        }

        $client = new McpDaemonClient();
        return $client->proxyRequest($request, $mcpService, $config, $baseUrl, $dfSessionToken);
    }

    /**
     * Validate Bearer token and return DF session token
     *
     * @return string|\Illuminate\Http\JsonResponse DF session token or error response
     */
    private function validateBearerToken(Request $request): string|\Illuminate\Http\JsonResponse
    {
        $authHeader = $request->header('Authorization');

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Bearer token required',
                ],
            ], 401);
        }

        $bearerToken = substr($authHeader, 7);
        $token = McpOAuthAccessToken::findValidAccessToken($bearerToken);

        if (!$token) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Invalid or expired token',
                ],
            ], 401);
        }

        return $token->getDfSessionToken();
    }
}
