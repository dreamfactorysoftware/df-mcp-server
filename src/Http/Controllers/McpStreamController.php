<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Models\McpCustomTool;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Utility\RequestLogger;
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

        if (!config('mcp.daemon.sse_enabled', false)) {
            return self::sseDisabledResponse();
        }

        return $this->processMcpRequest($request, $mcpService);
    }

    /**
     * Decline the optional server-initiated SSE stream.
     *
     * Proxying that stream through PHP-FPM pins one worker for its entire life:
     * McpDaemonClient issues a non-streaming Guzzle request, so the worker blocks
     * until the daemon closes the stream or the 300s timeout fires. Measured at
     * one worker per open stream, which exhausts a default pool after a handful
     * of connected clients. The MCP spec permits answering GET with 405, and the
     * daemon emits no server-initiated messages, so clients lose nothing by
     * falling back to POST-only. Flip MCP_SSE_ENABLED once the stream is served
     * by an evented layer rather than FPM.
     */
    private static function sseDisabledResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'Method Not Allowed: this endpoint does not offer a server-initiated SSE stream (POST only)',
            ],
        ], 405)->header('Allow', 'POST, DELETE');
    }

    /**
     * HEAD must answer exactly as GET would — same status and headers, no body
     * (RFC 9110 sec. 9.3.2). Previously no HEAD arm existed in the middleware, so
     * these fell through to DreamFactory's routing and returned 404, hiding the
     * 401 + WWW-Authenticate that drives OAuth discovery.
     *
     * This deliberately stops short of processMcpRequest(): a HEAD probe must not
     * open an MCP session against the daemon as a side effect.
     */
    public function handleHead(Request $request, string $mcpService)
    {
        $token = $this->validateBearerToken($request);
        if ($token instanceof \Illuminate\Http\JsonResponse) {
            return response('', $token->getStatusCode())
                ->withHeaders($token->headers->all());
        }

        $accept = strtolower($request->header('Accept', ''));
        if (!str_contains($accept, 'text/event-stream')) {
            return response('', 406);
        }

        // HEAD must mirror what GET would answer, so it declines too.
        if (!config('mcp.daemon.sse_enabled', false)) {
            return response('', 405)->withHeaders(self::sseDisabledResponse()->headers->all());
        }

        return response('', 200)->header('Content-Type', 'text/event-stream');
    }

    /**
     * nginx auth_request endpoint for the SSE stream.
     *
     * When nginx proxies GET /mcp/{service} straight to the Node daemon (so the
     * stream is held by an evented layer instead of a PHP-FPM worker), PHP no
     * longer sits in the data path — but it must still be the thing that decides
     * whether the request is allowed. nginx issues a subrequest here first; a 204
     * lets the stream through and carries the resolved DreamFactory context back
     * as headers, anything else aborts it.
     *
     * Returns the same 401 + WWW-Authenticate a direct GET would, so OAuth
     * discovery keeps working for unauthenticated clients.
     */
    public function authz(Request $request, string $mcpService)
    {
        $token = $this->validateBearerToken($request);
        if ($token instanceof \Illuminate\Http\JsonResponse) {
            return $token;
        }

        $config = $request->attributes->get('mcp_service_config');
        if (!$config) {
            // 403, not 404: nginx's auth_request only forwards 401 and 403 to the
            // client and turns every other status into a 500. Declining to
            // confirm whether an MCP service exists to a caller who is not
            // entitled to it is the better answer here anyway.
            return response()->json(['error' => 'MCP service not found', 'service' => $mcpService], 403);
        }

        SessionUtilities::setSessionData($config['app_id'] ?? null, $token->user_id);

        $headers = [
            'X-Mcp-Session-Token' => $token->getDfSessionToken(),
            'X-Mcp-Base-Url' => $this->resolveBaseUrl($request),
        ];

        if ($appId = ($config['app_id'] ?? null)) {
            if ($apiKey = \DreamFactory\Core\Models\App::getApiKeyByAppId($appId)) {
                $headers['X-Mcp-Api-Key'] = $apiKey;
            }
        }

        // The daemon only needs config when it has to build a server from
        // scratch; a GET normally resumes a session established by an earlier
        // POST, which already holds it. Pass it when it comfortably fits — Node
        // rejects a request whose headers total more than 16 KB.
        $encodedConfig = json_encode($config);
        if (is_string($encodedConfig) && strlen($encodedConfig) <= 8192) {
            $headers['X-Mcp-Config'] = $encodedConfig;
        }

        return response('', 204)->withHeaders($headers);
    }

    /**
     * External base URL the daemon should use for its DreamFactory REST calls.
     */
    private function resolveBaseUrl(Request $request): string
    {
        if ($internalBase = config('mcp.daemon.internal_base_url')) {
            return rtrim($internalBase, '/') . '/api/v2';
        }

        $scheme = $request->header('X-Forwarded-Proto') ?: $request->getScheme();
        if ($request->secure() || str_starts_with($request->fullUrl(), 'https://')) {
            $scheme = 'https';
        }

        return $scheme . '://' . $request->getHttpHost() . '/api/v2';
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
        $startNs = hrtime(true);

        // Validate Bearer token
        $token = $this->validateBearerToken($request);
        if ($token instanceof \Illuminate\Http\JsonResponse) {
            // Don't audit-log failed token validations — service_id can't be
            // attributed to a session and the noise dwarfs the signal.
            return $token;
        }

        $dfSessionToken = $token->getDfSessionToken();

        // Get service configuration from request (set by middleware)
        $config = $request->attributes->get('mcp_service_config');
        if (!$config) {
            try { RequestLogger::log($mcpService, $request, $token, $startNs, 0, 'error', 'MCP service not found'); } catch (\Throwable $e) { /* never break the response on audit failure */ }
            return response()->json([
                'error' => 'MCP service not found',
                'service' => $mcpService,
            ], 404);
        }

        // Initialize DreamFactory session context so role.services is populated.
        // McpStreamMiddleware runs before AuthCheck, so we must set this up manually.
        $appId = $config['app_id'] ?? null;
        SessionUtilities::setSessionData($appId, $token->user_id);

        // Resolve lookup placeholders in custom tool configs (headers, URLs, parameters).
        // Must run AFTER setSessionData() so the user's lookup maps are populated.
        // Function bodies are excluded — they receive secrets via the secrets object below.
        if (!empty($config['custom_tools']) && is_array($config['custom_tools'])) {
            $functionBodies = [];
            foreach ($config['custom_tools'] as $i => $tool) {
                if (isset($tool['function'])) {
                    $functionBodies[$i] = $tool['function'];
                    unset($config['custom_tools'][$i]['function']);
                }
            }
            SessionUtilities::replaceLookups($config['custom_tools'], true);
            foreach ($functionBodies as $i => $body) {
                $config['custom_tools'][$i]['function'] = $body;
            }

            // Resolve SCM-linked function bodies from GitHub/GitLab/Bitbucket.
            // Must run before extractFunctionSecrets so secrets.KEY references
            // in GitHub-hosted code are found and resolved.
            $this->resolveScmFunctionBodies($config['custom_tools']);

            // Scan function bodies for secrets.KEY references, resolve the lookup
            // values, and attach a secrets map so the daemon can inject them at runtime
            // without the values ever appearing in the JS source string.
            $this->extractFunctionSecrets($config['custom_tools']);
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

        // Use internal base URL when configured (e.g. Docker where external port differs from internal)
        $internalBase = config('mcp.daemon.internal_base_url');
        if (!empty($internalBase)) {
            $baseUrl = rtrim($internalBase, '/') . '/api/v2';
        } else {
            $baseUrl = $scheme . '://' . $host . '/api/v2';
        }

        if (!config('mcp.daemon.enabled', false)) {
            try { RequestLogger::log($mcpService, $request, $token, $startNs, 0, 'error', 'MCP daemon disabled'); } catch (\Throwable $ignored) { /* never break the response */ }
            return response()->json([
                'error' => 'MCP daemon is disabled. Please set MCP_DAEMON_ENABLED=true and run the Node daemon.'
            ], 503);
        }

        // Resolve available services server-side so the daemon doesn't need
        // to call GET /api/v2/system/service (which requires system permissions).
        $availableServices = $this->getAvailableServices();

        $client = new McpDaemonClient();
        try {
            $response = $client->proxyRequest($request, $mcpService, $config, $baseUrl, $dfSessionToken, $availableServices);
            try {
                $bytesOut = self::responseBytes($response);
                $status = $response->getStatusCode() >= 400 ? 'error' : 'success';
                RequestLogger::log($mcpService, $request, $token, $startNs, $bytesOut, $status);
            } catch (\Throwable $ignored) {
                /* audit logging must never break the response */
            }
            return $response;
        } catch (\Throwable $e) {
            try { RequestLogger::log($mcpService, $request, $token, $startNs, 0, 'error', $e->getMessage()); } catch (\Throwable $ignored) { /* belt and suspenders */ }
            throw $e;
        }
    }

    /**
     * Best-effort byte count for the proxied response. Streaming responses
     * (SSE GETs) return 0 — exact byte counting would require wrapping the
     * stream, which isn't worth the complexity for an audit log.
     */
    private static function responseBytes($response): int
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return 0;
        }
        if (method_exists($response, 'getContent')) {
            $content = $response->getContent();
            return is_string($content) ? strlen($content) : 0;
        }
        return 0;
    }

    /**
     * Scan function bodies for secrets.KEY references, resolve the corresponding
     * lookup values, and attach a secrets map to each tool definition.
     *
     * Only secrets actually referenced in the function body are included
     * (principle of least privilege). Unresolved references are silently
     * omitted — secrets.MISSING evaluates to undefined in JS.
     */
    private function extractFunctionSecrets(array &$customTools): void
    {
        foreach ($customTools as &$tool) {
            if (empty($tool['function']) || !is_string($tool['function'])) {
                continue;
            }

            $secrets = [];
            if (preg_match_all('/secrets\.(\w+)/', $tool['function'], $matches)) {
                foreach ($matches[1] as $key) {
                    $value = null;
                    if (SessionUtilities::getLookupValue($key, $value, true)) {
                        $secrets[$key] = $value;
                    }
                }
            }

            if (!empty($secrets)) {
                $tool['secrets'] = $secrets;
            }
        }
    }

    /**
     * Resolve function bodies from linked SCM services for function-type custom tools.
     *
     * For each tool with a storage_service_id, fetches the function body from the
     * configured GitHub/GitLab/Bitbucket service and overwrites the function field.
     * If the fetch fails, the existing inline function body (if any) is kept as fallback.
     */
    private function resolveScmFunctionBodies(array &$customTools): void
    {
        foreach ($customTools as &$tool) {
            $toolType = $tool['tool_type'] ?? 'api';
            $storageServiceId = $tool['storage_service_id'] ?? null;

            if ($toolType !== 'function' || empty($storageServiceId)) {
                continue;
            }

            $content = McpCustomTool::resolveScmFunctionBody(
                $storageServiceId,
                $tool['scm_repository'] ?? null,
                $tool['scm_reference'] ?? null,
                $tool['storage_path'] ?? null
            );

            if ($content !== null) {
                $tool['function'] = $content;
            }
        }
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
                if ($accessibleIds === null) {
                    // null means role grants access to all services — no filtering needed
                } elseif (!empty($accessibleIds)) {
                    $allServices = array_values(array_filter(
                        $allServices,
                        fn($s) => in_array((int)($s['id'] ?? 0), $accessibleIds, true)
                    ));
                } else {
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
     * @return int[]|null  Array of service IDs, or null if role grants access to ALL services.
     */
    private function getUserAccessibleServiceIds(): ?array
    {
        $roleServices = (array)SessionUtilities::get('role.services');
        $ids = [];

        foreach ($roleServices as $serviceAccess) {
            $serviceId = $serviceAccess['service_id'] ?? null;
            if ($serviceId === null || $serviceId === 0 || $serviceId === '') {
                // A null service_id entry means "all services"
                return null;
            }
            if (is_numeric($serviceId) && $serviceId > 0) {
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
