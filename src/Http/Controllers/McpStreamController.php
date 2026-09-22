<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use DreamFactory\Core\McpServer\Support\DaemonTarget;
use DreamFactory\Core\McpServer\Support\SecretFieldManifest;
use DreamFactory\Core\McpServer\Models\McpCustomTool;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Utility\ApiKeyAuth;
use DreamFactory\Core\McpServer\Utility\AvailableServices;
use DreamFactory\Core\McpServer\Utility\RequestLogger;
use DreamFactory\Core\McpServer\Utility\RoleAccessGate;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class McpStreamController extends Controller
{
    public function handleGet(Request $request, string $mcpService)
    {
        // Authenticate FIRST so unauthenticated GET requests receive
        // 401 + WWW-Authenticate (triggering OAuth discovery) rather than 406,
        // which clients like Claude.ai cannot act on.
        $auth = $this->authenticateRequest($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            return $auth;
        }

        // Decline the server-initiated SSE stream (MCP spec allows 405; clients
        // fall back to POST-only). Proxying it held one PHP-FPM worker per MCP
        // session for the full daemon timeout because the stream never ends
        // and Guzzle buffers it, which starved the pool under a handful of
        // concurrent sessions (#50). The daemon never pushes notifications on
        // that stream, so nothing is lost.
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32000,
                'message' => 'Method Not Allowed: server-initiated SSE stream is not offered (POST only)',
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

        return response('', 200)->header('Content-Type', 'text/event-stream');
    }

    public function handlePost(Request $request, string $mcpService)
    {
        return $this->processMcpRequest($request, $mcpService);
    }

    public function handleDelete(Request $request, string $mcpService)
    {
        return $this->processMcpRequest($request, $mcpService);
    }

    /**
     * @param array|null $auth Pre-computed auth result (from handleGet) so the
     *        credentials are not validated twice on SSE connects.
     */
    private function processMcpRequest(Request $request, string $mcpService, ?array $auth = null)
    {
        $startNs = hrtime(true);

        // Authenticate: OAuth Bearer (always wins), or a static API key when
        // the service opted in via allow_api_key_auth.
        $auth ??= $this->authenticateRequest($request);
        if ($auth instanceof \Illuminate\Http\JsonResponse) {
            // Don't audit-log failed credential validations — service_id can't
            // be attributed to a session and the noise dwarfs the signal.
            return $auth;
        }

        /** @var McpOAuthAccessToken|null $token null for API-key auth */
        $token = $auth['token'];
        $dfSessionToken = $auth['session_token']; // null for key-only auth

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
        if (($auth['auth_type'] ?? '') === ApiKeyAuth::MODE_API_KEY) {
            // Mirror df-core's AuthCheck for API-key requests: record the key,
            // then establish the session from the key's app (NOT the service's
            // configured app). With no user, setSessionData() picks up the
            // app's role, so the role-filtered service list works off the
            // app's role; with a layered session token, the user's app-role
            // mapping wins, exactly as in the REST pipeline.
            SessionUtilities::setApiKey($auth['api_key']);
            if (!empty($dfSessionToken)) {
                SessionUtilities::setSessionToken($dfSessionToken);
            }
            SessionUtilities::setSessionData($auth['app_id'], $auth['user_id']);
        } else {
            $appId = $config['app_id'] ?? null;
            SessionUtilities::setSessionData($appId, $token->user_id);
        }

        // "Require role access": with the switch on, a non-admin identity needs a
        // grant on this MCP service itself before it may connect. Runs after the
        // session is seeded (role.services populated) and before anything is
        // resolved or proxied, so a refused identity learns nothing about the
        // catalog. Admins always pass.
        if (RoleAccessGate::requires($config) && !RoleAccessGate::sessionMayConnect($mcpService)) {
            $message = RoleAccessGate::denialMessage($mcpService);
            try { RequestLogger::log($mcpService, $request, $token, $startNs, 0, 'denied', $message); } catch (\Throwable $e) { /* never break the response on audit failure */ }
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => RoleAccessGate::ERROR_CODE,
                    'message' => $message,
                ],
            ], 403);
        }

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

        // Pick the daemon by service type: `system_mcp` -> df-system-mcp-server,
        // everything else -> the bundled data daemon.
        $serviceType = $request->attributes->get('mcp_service_type');
        $target = DaemonTarget::forServiceType(is_string($serviceType) ? $serviceType : null);

        // The daemon's callback base: the target's configured base URL when set (e.g. Docker,
        // where the external host or port differs from internal), else this request's origin.
        $baseUrl = DaemonTarget::apiBaseUrl($target, $scheme . '://' . $host);

        if (!$target['enabled']) {
            try { RequestLogger::log($mcpService, $request, $token, $startNs, 0, 'error', $target['label'] . ' disabled'); } catch (\Throwable $ignored) { /* never break the response */ }
            return response()->json([
                'error' => $target['disabled_message'],
            ], 503);
        }

        // Resolve available services server-side so the daemon doesn't need
        // to call GET /api/v2/system/service (which requires system permissions).
        // The system daemon exposes the System API itself and never auto-mounts
        // DB/file services, so skip the lookup for it. The data daemon's catalog
        // is scoped to this MCP service when scope_tools / exposed_services /
        // MCP_SCOPE_TOOLS is set — otherwise the historical instance-wide catalog.
        $availableServices = McpServiceTypes::isSystem($target['type'])
            ? []
            : AvailableServices::resolve($mcpService, is_array($config) ? $config : []);

        $client = new McpDaemonClient($target['url']);
        // The system daemon masks service configs using DreamFactory's own secret field metadata.
        if (McpServiceTypes::isSystem($target['type'])) {
            $client->withSecretFields(SecretFieldManifest::cached());
        }
        try {
            $response = $client->proxyRequest($request, $mcpService, $config, $baseUrl, $auth, $availableServices);
            // The daemon's savings ledger is internal — persist it, don't forward it to the MCP client.
            $ledger = json_decode((string) $response->headers->get('X-Mcp-Ledger'), true);
            $response->headers->remove('X-Mcp-Ledger');
            try {
                $bytesOut = self::responseBytes($response);
                $status = $response->getStatusCode() >= 400 ? 'error' : 'success';
                RequestLogger::log($mcpService, $request, $token, $startNs, $bytesOut, $status, null, is_array($ledger) ? $ledger : null);
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
     * Authenticate the request: OAuth Bearer token, or a static API key when
     * the service has opted in via allow_api_key_auth.
     *
     * Bearer always wins — a request carrying `Authorization: Bearer ...` goes
     * through the unchanged OAuth validation path regardless of any API-key
     * headers. Without a Bearer credential, the API-key path applies only when
     * the service's allow_api_key_auth flag is enabled; otherwise the response
     * is the exact same 401 Bearer-required (+ WWW-Authenticate) as before.
     *
     * @return array|\Illuminate\Http\JsonResponse Auth result with keys:
     *         auth_type ('oauth'|'api_key'), token (McpOAuthAccessToken|null),
     *         session_token (?string), api_key (?string), app_id (?int),
     *         user_id (?int) — or an error response.
     */
    private function authenticateRequest(Request $request): array|\Illuminate\Http\JsonResponse
    {
        $config = $request->attributes->get('mcp_service_config');

        $mode = ApiKeyAuth::decide(
            $request->header('Authorization'),
            ApiKeyAuth::allowsApiKeyAuth(is_array($config) ? $config : null)
        );

        if ($mode === ApiKeyAuth::MODE_API_KEY) {
            return $this->validateApiKeyAuth($request);
        }

        // MODE_BEARER validates the presented token; MODE_UNAUTHORIZED falls
        // through to the same method's 401 Bearer-required response, keeping
        // pre-existing behavior byte-for-byte for services without the flag.
        $token = $this->validateBearerToken($request);
        if ($token instanceof \Illuminate\Http\JsonResponse) {
            return $token;
        }

        return [
            'auth_type' => 'oauth',
            'token' => $token,
            'session_token' => $token->getDfSessionToken(),
            'api_key' => null,
            'app_id' => null,
            'user_id' => $token->user_id,
        ];
    }

    /**
     * Validate API-key authentication: the key must resolve to an existing,
     * active app with a role assigned. An optional X-DreamFactory-Session-Token
     * (DF JWT) layers user identity/RBAC on top of the app context.
     *
     * @return array|\Illuminate\Http\JsonResponse Auth result or error response
     */
    private function validateApiKeyAuth(Request $request): array|\Illuminate\Http\JsonResponse
    {
        $apiKey = trim((string) $request->header(ApiKeyAuth::HEADER_API_KEY, ''));

        if ($apiKey === '') {
            return $this->unauthorizedResponse($request, 'Unauthorized: API key required (X-DreamFactory-API-Key header)');
        }

        // Format gate (64-char hex, the shape of every DF-generated key)
        // before any cache/DB lookup. Same message as an unknown key so the
        // response doesn't distinguish malformed from nonexistent.
        if (!ApiKeyAuth::isValidKeyFormat($apiKey)) {
            return $this->unauthorizedResponse($request, 'Unauthorized: Invalid API key');
        }

        $appId = App::getAppIdByApiKey($apiKey);
        if (!$appId) {
            return $this->unauthorizedResponse($request, 'Unauthorized: Invalid API key');
        }

        $app = App::find($appId);
        $rejection = ApiKeyAuth::appRejection(
            $app ? ['is_active' => $app->is_active, 'role_id' => $app->role_id] : null
        );
        if ($rejection !== null) {
            return $this->unauthorizedResponse($request, 'Unauthorized: ' . $rejection);
        }

        // Optional session token for user-specific RBAC on top of the app.
        $sessionToken = trim((string) $request->header(ApiKeyAuth::HEADER_SESSION_TOKEN, ''));
        $userId = null;
        if ($sessionToken !== '') {
            $validated = $this->validateSessionToken($request, $sessionToken);
            if ($validated instanceof \Illuminate\Http\JsonResponse) {
                return $validated;
            }
            $userId = $validated;
        } else {
            $sessionToken = null;
        }

        Log::debug('MCP API key auth successful', [
            'app_id' => $appId,
            'app_name' => $app->name,
            'has_session_token' => !empty($sessionToken),
        ]);

        return [
            'auth_type' => 'api_key',
            'token' => null,
            'session_token' => $sessionToken, // null for API-key-only auth
            'api_key' => $apiKey,
            'app_id' => $appId,
            'user_id' => $userId,
        ];
    }

    /**
     * Validate a DreamFactory session token (JWT) the same way df-core's
     * AuthCheck middleware does: signature/expiry via JWTAuth, then the
     * user-mapping check via JWTUtilities::verifyUser().
     *
     * @return int|null|\Illuminate\Http\JsonResponse The token's user id, or an error response
     */
    private function validateSessionToken(Request $request, string $sessionToken): int|null|\Illuminate\Http\JsonResponse
    {
        try {
            \JWTAuth::setToken($sessionToken);
            /** @var \Tymon\JWTAuth\Payload $payload */
            $payload = \JWTAuth::getPayload();
            JWTUtilities::verifyUser($payload);

            $userId = $payload->get('user_id');

            return is_numeric($userId) ? (int) $userId : null;
        } catch (\Throwable $e) {
            Log::warning('MCP session token validation failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorizedResponse($request, 'Unauthorized: Invalid or expired session token');
        }
    }

    /**
     * JSON-RPC 401 with the OAuth discovery header, so clients that fail
     * API-key auth can still fall back to the OAuth flow.
     */
    private function unauthorizedResponse(Request $request, string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32001,
                'message' => $message,
            ],
        ], 401)->header('WWW-Authenticate', $this->buildWwwAuthenticateHeader($request));
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
