<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\Models\App;
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
        // Get service configuration from request (set by middleware)
        $config = $request->attributes->get('mcp_service_config');
        if (!$config) {
            return response()->json([
                'error' => 'MCP service not found',
                'service' => $mcpService,
            ], 404);
        }

        // Authenticate the request (OAuth or API key)
        $authResult = $this->authenticateRequest($request, $config);
        if ($authResult instanceof \Illuminate\Http\JsonResponse) {
            return $authResult;
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
        return $client->proxyRequest($request, $mcpService, $config, $baseUrl, $authResult);
    }

    /**
     * Authenticate the request using either OAuth or API key
     *
     * @param Request $request
     * @param array $config Service configuration
     * @return array|\Illuminate\Http\JsonResponse Auth result array or error response
     */
    private function authenticateRequest(Request $request, array $config): array|\Illuminate\Http\JsonResponse
    {
        // Try OAuth Bearer token first
        $authHeader = $request->header('Authorization');
        if (!empty($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            return $this->validateBearerToken($request);
        }

        // Check if API key auth is enabled for this service
        $allowApiKeyAuth = $config['allow_api_key_auth'] ?? false;
        if (!$allowApiKeyAuth) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Bearer token required',
                ],
            ], 401);
        }

        // Try API key authentication
        return $this->validateApiKeyAuth($request);
    }

    /**
     * Validate Bearer token and return auth result
     *
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse Auth result or error response
     */
    private function validateBearerToken(Request $request): array|\Illuminate\Http\JsonResponse
    {
        $authHeader = $request->header('Authorization');
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

        return [
            'auth_type' => 'oauth',
            'session_token' => $token->getDfSessionToken(),
            'api_key' => null,
            'app_id' => null,
        ];
    }

    /**
     * Validate API key authentication
     *
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse Auth result or error response
     */
    private function validateApiKeyAuth(Request $request): array|\Illuminate\Http\JsonResponse
    {
        $apiKey = $request->header('X-DreamFactory-API-Key');

        if (empty($apiKey)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: API key required (X-DreamFactory-API-Key header)',
                ],
            ], 401);
        }

        // Validate the API key and get app ID
        $appId = App::getAppIdByApiKey($apiKey);
        if (!$appId) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Invalid API key',
                ],
            ], 401);
        }

        // Get the app and validate it's active with a role
        $app = App::find($appId);
        if (!$app) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: App not found',
                ],
            ], 401);
        }

        if (!$app->is_active) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: App is not active',
                ],
            ], 401);
        }

        if (empty($app->role_id)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: App must have a role assigned for API key authentication',
                ],
            ], 401);
        }

        // Check for optional session token for user-specific RBAC
        $sessionToken = $request->header('X-DreamFactory-Session-Token');
        if (!empty($sessionToken)) {
            $sessionValidation = $this->validateSessionToken($sessionToken);
            if ($sessionValidation instanceof \Illuminate\Http\JsonResponse) {
                return $sessionValidation;
            }
            // Use the validated session token
            $sessionToken = $sessionValidation;
        }

        Log::debug('MCP API key auth successful', [
            'app_id' => $appId,
            'app_name' => $app->name,
            'has_session_token' => !empty($sessionToken),
        ]);

        return [
            'auth_type' => 'api_key',
            'session_token' => $sessionToken, // May be null for API-key-only auth
            'api_key' => $apiKey,
            'app_id' => $appId,
        ];
    }

    /**
     * Validate a DreamFactory session token (JWT)
     *
     * @param string $sessionToken
     * @return string|\Illuminate\Http\JsonResponse Validated token or error response
     */
    private function validateSessionToken(string $sessionToken): string|\Illuminate\Http\JsonResponse
    {
        try {
            // Use DreamFactory's JWT utilities to validate the token
            $payload = \DreamFactory\Core\Utility\JWTUtilities::decode($sessionToken);

            if (!$payload) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32001,
                        'message' => 'Unauthorized: Invalid session token',
                    ],
                ], 401);
            }

            return $sessionToken;
        } catch (\Exception $e) {
            Log::warning('MCP session token validation failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Invalid or expired session token',
                ],
            ], 401);
        }
    }
}
