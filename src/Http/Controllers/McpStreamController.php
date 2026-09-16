<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use DreamFactory\Core\McpServer\Support\DaemonTarget;
use DreamFactory\Core\McpServer\Support\SecretFieldManifest;
use DreamFactory\Core\McpServer\Models\McpCustomTool;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Utility\AvailableServices;
use DreamFactory\Core\McpServer\Utility\RequestLogger;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use Illuminate\Http\Request;

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
            $response = $client->proxyRequest($request, $mcpService, $config, $baseUrl, $dfSessionToken, $availableServices);
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
