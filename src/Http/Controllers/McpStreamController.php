<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class McpStreamController extends Controller
{
    public function handleGet(Request $request, string $mcpService)
    {
        // Validate the Bearer token FIRST so unauthenticated GET requests receive
        // 401 + WWW-Authenticate (triggering OAuth discovery) rather than 406,
        // which clients like Claude.ai cannot act on.
        $token = $this->validateBearerToken($request);
        if ($token instanceof \Illuminate\Http\JsonResponse) {
            return $token;
        }

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

    public function handleDelete(Request $request, string $mcpService)
    {
        return $this->processMcpRequest($request, $mcpService);
    }

    private function processMcpRequest(Request $request, string $mcpService)
    {
        // Validate Bearer token
        $token = $this->validateBearerToken($request);
        if ($token instanceof \Illuminate\Http\JsonResponse) {
            return $token;
        }

        $dfSessionToken = $token->getDfSessionToken();

        // Get service configuration from request (set by middleware)
        $config = $request->attributes->get('mcp_service_config');
        if (!$config) {
            return response()->json([
                'error' => 'MCP service not found',
                'service' => $mcpService,
            ], 404);
        }

        // Initialize DreamFactory session context so role.services is populated.
        // McpStreamMiddleware runs before AuthCheck, so we must set this up manually.
        $appId = $config['app_id'] ?? null;
        SessionUtilities::setSessionData($appId, $token->user_id);

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

        // Use internal base URL when configured (e.g. Docker where external port differs from internal)
        $internalBase = config('mcp.daemon.internal_base_url');
        if (!empty($internalBase)) {
            $baseUrl = rtrim($internalBase, '/') . '/api/v2';
        } else {
            $baseUrl = $scheme . '://' . $host . '/api/v2';
        }

        if (!config('mcp.daemon.enabled', false)) {
            return response()->json([
                'error' => 'MCP daemon is disabled. Please set MCP_DAEMON_ENABLED=true and run the Node daemon.'
            ], 503);
        }

        // Resolve available services server-side so the daemon doesn't need
        // to call GET /api/v2/system/service (which requires system permissions).
        $availableServices = $this->getAvailableServices();

        $client = new McpDaemonClient();
        return $client->proxyRequest($request, $mcpService, $config, $baseUrl, $dfSessionToken, $availableServices);
    }

    /**
     * Get available database and file services, filtered by the user's role.
     *
     * Uses ServiceManager internally (bypasses HTTP RBAC middleware), then
     * filters the result using the session's role.services — the same pattern
     * used by Service::getUserAccessibleServices().
     *
     * @return array List of services with name, label, type, and category
     */
    private function getAvailableServices(): array
    {
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $serviceManager */
            $serviceManager = app('df.service');
            $fields = ['id', 'name', 'label', 'type'];

            $dbServices = $serviceManager->getServiceListByGroup(ServiceTypeGroups::DATABASE, $fields, true);
            $fileServices = $serviceManager->getServiceListByGroup(ServiceTypeGroups::FILE, $fields, true);

            $allServices = array_merge(
                array_map(fn($s) => array_merge($s, ['category' => 'database']), $dbServices),
                array_map(fn($s) => array_merge($s, ['category' => 'file']), $fileServices)
            );

            // Sysadmins get all services; non-admin users are filtered by role
            if (!SessionUtilities::isSysAdmin()) {
                $accessibleIds = $this->getUserAccessibleServiceIds();
                if (!empty($accessibleIds)) {
                    $allServices = array_values(array_filter(
                        $allServices,
                        fn($s) => in_array((int)($s['id'] ?? 0), $accessibleIds, true)
                    ));
                } else {
                    // Role has no service access entries — return nothing
                    $allServices = [];
                }
            }

            return $allServices;
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve available services for MCP daemon', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get service IDs the current user's role has access to.
     *
     * Mirrors Service::getUserAccessibleServices() — reads the role_service_access
     * entries stored in the session by Session::setSessionData().
     *
     * @return int[]
     */
    private function getUserAccessibleServiceIds(): array
    {
        $roleServices = (array)SessionUtilities::get('role.services');
        $ids = [];

        foreach ($roleServices as $serviceAccess) {
            $serviceId = $serviceAccess['service_id'] ?? null;
            if (!empty($serviceId) && is_numeric($serviceId) && $serviceId > 0) {
                $ids[] = (int)$serviceId;
            }
        }

        return array_unique($ids);
    }

    /**
     * Validate Bearer token and return the token model
     *
     * @return McpOAuthAccessToken|\Illuminate\Http\JsonResponse Token model or error response
     */
    private function validateBearerToken(Request $request): McpOAuthAccessToken|\Illuminate\Http\JsonResponse
    {
        $authHeader = $request->header('Authorization');
        $wwwAuthenticate = $this->buildWwwAuthenticateHeader($request);

        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Bearer token required',
                ],
            ], 401)->header('WWW-Authenticate', $wwwAuthenticate);
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
            ], 401)->header('WWW-Authenticate', $wwwAuthenticate . ', error="invalid_token", error_description="Token is invalid or expired"');
        }

        return $token;
    }

    /**
     * Build the WWW-Authenticate header value pointing to the resource metadata endpoint.
     * Required by RFC 6750 and the MCP OAuth spec so clients can auto-discover OAuth endpoints.
     */
    private function buildWwwAuthenticateHeader(Request $request): string
    {
        $mcpService = $request->attributes->get('mcp_service_name', '');

        $scheme = $request->header('X-Forwarded-Proto') ?: $request->getScheme();
        if ($request->secure() || str_starts_with($request->fullUrl(), 'https://')) {
            $scheme = 'https';
        }

        $host = $request->getHttpHost();
        $resourceMetadataUrl = $scheme . '://' . $host . '/mcp/' . $mcpService . '/.well-known/oauth-protected-resource';

        return 'Bearer realm="MCP", resource_metadata="' . $resourceMetadataUrl . '"';
    }
}
