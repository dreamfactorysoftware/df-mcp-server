<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Models\McpOAuthAuthorizationCode;
use DreamFactory\Core\McpServer\Models\McpOAuthClient;
use DreamFactory\Core\McpServer\Models\McpServerConfig;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 Authorization Server for MCP
 *
 * Implements OAuth 2.0 Authorization Code flow with PKCE support.
 */
class McpOAuthController extends Controller
{
    private string $dfUrl;

    public function __construct()
    {
        // Internal API URL for server-to-server calls (not proxied)
        $this->dfUrl = rtrim(config('app.url', env('DF_URL', 'http://localhost')), '/');
    }

    /**
     * Get frontend URL for login redirects
     *
     * Uses the request's base URL (respecting X-Forwarded-* headers from proxies)
     * combined with the frontend path. Can be overridden with DF_FRONTEND_URL.
     */
    private function getFrontendUrl(Request $request): string
    {
        $frontendUrl = env('DF_FRONTEND_URL');
        if ($frontendUrl) {
            return rtrim($frontendUrl, '/');
        }

        $baseUrl = $this->getBaseUrl($request);
        $frontendPath = env('DF_FRONTEND_PATH', '/dreamfactory/dist/#');
        return rtrim($baseUrl . $frontendPath, '/');
    }

    /**
     * OAuth Discovery: Protected Resource Metadata (RFC 9728)
     * GET /mcp/{service}/.well-known/oauth-protected-resource
     */
    public function protectedResourceMetadata(Request $request, string $mcpService)
    {
        $baseUrl = $this->getBaseUrl($request);

        return response()->json([
            'resource' => "{$baseUrl}/mcp/{$mcpService}",
            'authorization_servers' => ["{$baseUrl}/mcp/{$mcpService}"],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /**
     * OAuth Discovery: Authorization Server Metadata (RFC 8414)
     * GET /mcp/{service}/.well-known/oauth-authorization-server
     */
    public function authorizationServerMetadata(Request $request, string $mcpService)
    {
        $baseUrl = $this->getBaseUrl($request);

        return response()->json([
            'issuer' => "{$baseUrl}/mcp/{$mcpService}",
            'authorization_endpoint' => "{$baseUrl}/mcp/{$mcpService}/authorize",
            'token_endpoint' => "{$baseUrl}/mcp/{$mcpService}/token",
            'registration_endpoint' => "{$baseUrl}/mcp/{$mcpService}/register",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'scopes_supported' => ['mcp:tools', 'mcp:resources', 'mcp:prompts'],
        ]);
    }

    /**
     * Dynamic Client Registration (RFC 7591)
     * POST /mcp/{service}/register
     *
     * Supports MCP client registration for AI assistants like Claude Desktop.
     * Returns pre-configured credentials along with client-provided redirect_uris.
     */
    public function register(Request $request, string $mcpService)
    {
        $serviceConfig = $request->attributes->get('mcp_service_config');

        if (!$serviceConfig || empty($serviceConfig['oauth_client_id'])) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'OAuth not configured for this MCP service.',
            ], 400);
        }

        // Get client-provided redirect_uris from the registration request
        $requestedRedirectUris = $request->input('redirect_uris', []);
        $clientName = $request->input('client_name', $mcpService);

        // Validate redirect_uris - must be valid URLs
        $validatedRedirectUris = [];
        foreach ($requestedRedirectUris as $uri) {
            if (!is_string($uri)) {
                continue;
            }
            $parsed = parse_url($uri);
            // Accept https:// URIs and localhost for development
            if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
                if ($parsed['scheme'] === 'https' ||
                    ($parsed['scheme'] === 'http' && in_array($parsed['host'], ['localhost', '127.0.0.1']))) {
                    $validatedRedirectUris[] = $uri;
                }
            }
        }

        // Always allow Claude's known redirect URIs for compatibility
        $claudeRedirectUris = [
            'https://claude.ai/api/mcp/auth_callback',
            'https://claude.com/api/mcp/auth_callback',
        ];

        // Merge with any existing redirect_uris, avoiding duplicates
        $client = McpOAuthClient::findByClientId($serviceConfig['oauth_client_id']);
        if ($client) {
            $existingUris = $client->redirect_uris ?? [];
            $allUris = array_unique(array_merge($existingUris, $validatedRedirectUris, $claudeRedirectUris));
            $client->update(['redirect_uris' => array_values($allUris)]);
            $validatedRedirectUris = $allUris;
        } else {
            // Create client entry if it doesn't exist
            $allUris = array_unique(array_merge($validatedRedirectUris, $claudeRedirectUris));
            McpOAuthClient::create([
                'client_id' => $serviceConfig['oauth_client_id'],
                'client_secret' => $serviceConfig['oauth_client_secret'],
                'name' => $clientName,
                'redirect_uris' => array_values($allUris),
                'is_active' => true,
            ]);
            $validatedRedirectUris = $allUris;
        }

        Log::info('MCP OAuth: Client registration', [
            'client_name' => $clientName,
            'redirect_uris' => $validatedRedirectUris,
            'service' => $mcpService,
        ]);

        // Return registration response per RFC 7591
        // Include client_secret for MCP clients (required for token exchange)
        return response()->json([
            'client_id' => $serviceConfig['oauth_client_id'],
            'client_secret' => $serviceConfig['oauth_client_secret'],
            'client_name' => $clientName,
            'redirect_uris' => array_values($validatedRedirectUris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'client_secret_post',
            'scope' => 'mcp:tools mcp:resources mcp:prompts',
        ]);
    }

    /**
     * Authorization Endpoint - Check for existing session or show login page
     * GET /mcp/{service}/authorize
     */
    public function authorizeGet(Request $request, string $mcpService)
    {
        $clientId = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');
        $state = $request->query('state');
        $codeChallenge = $request->query('code_challenge');
        $codeChallengeMethod = $request->query('code_challenge_method', 'plain');
        $responseType = $request->query('response_type', 'code');
        $scope = $request->query('scope', 'mcp:tools mcp:resources mcp:prompts');

        // Validate response_type
        if ($responseType !== 'code') {
            return $this->errorResponse('unsupported_response_type', 'Only code response type is supported');
        }

        // Validate client_id is provided
        if (empty($clientId)) {
            return $this->errorResponse('invalid_request', 'Missing client_id');
        }

        // Get service config and validate client_id matches
        $serviceConfig = $request->attributes->get('mcp_service_config');
        if (!$serviceConfig) {
            return $this->errorResponse('server_error', 'MCP service not found');
        }

        // Validate client_id matches configured OAuth client
        if (empty($serviceConfig['oauth_client_id'])) {
            return $this->errorResponse('server_error', 'OAuth not configured for this service');
        }

        if ($clientId !== $serviceConfig['oauth_client_id']) {
            Log::warning('MCP OAuth: Invalid client_id', [
                'provided' => $clientId,
                'expected' => $serviceConfig['oauth_client_id'],
                'service' => $mcpService,
            ]);
            return $this->errorResponse('invalid_client', 'Invalid client_id');
        }

        // Ensure client exists in the OAuth clients table (for foreign key constraints)
        $client = McpOAuthClient::findByClientId($clientId);
        if (!$client) {
            // Create the client entry for the pre-configured credentials
            $client = McpOAuthClient::create([
                'client_id' => $clientId,
                'client_secret' => $serviceConfig['oauth_client_secret'],
                'name' => $mcpService,
                'redirect_uris' => $redirectUri ? [$redirectUri] : [],
                'is_active' => true,
            ]);
        }

        // Validate redirect URI
        if (empty($redirectUri)) {
            return $this->errorResponse('invalid_request', 'Missing redirect_uri');
        }

        // ============================================================
        // SSO CHECK: Try to find existing DreamFactory session
        // If user is already logged in, skip the login page entirely
        // ============================================================
        $existingSession = $this->getExistingDfSession($request);
        if ($existingSession) {
            Log::info('MCP OAuth: Found existing DF session, skipping login page', [
                'user_id' => $existingSession['id'],
                'email' => $existingSession['email'],
            ]);

            // User is already authenticated - generate code and redirect immediately
            $authCode = McpOAuthAuthorizationCode::createCode([
                'client_id' => $clientId,
                'user_id' => $existingSession['id'],
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $codeChallengeMethod ?: 'S256',
                'scope' => $scope,
                'df_session_token' => $existingSession['session_token'],
                'user_email' => $existingSession['email'],
                'user_name' => $existingSession['name'] ?? $existingSession['first_name'] ?? null,
            ]);

            // Build redirect URL back to the client
            $redirectParams = ['code' => $authCode->code];
            if (!empty($state)) {
                $redirectParams['state'] = $state;
            }

            $redirectUrl = $this->buildRedirectUrl($redirectUri, $redirectParams);

            return redirect($redirectUrl);
        }

        // ============================================================
        // No existing session - redirect to login page
        // ============================================================

        // Generate internal state for tracking
        $authState = bin2hex(random_bytes(16));

        // Store pending authorization in cache
        $pendingKey = 'mcp_oauth_pending_' . $authState;
        cache()->put($pendingKey, [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'scope' => $scope,
            'service' => $mcpService,
        ], now()->addMinutes(10));

        $baseUrl = $this->getBaseUrl($request);

        // ============================================================
        // Check for custom login URL configuration
        // ============================================================
        if (!empty($serviceConfig['custom_login_url'])) {
            // Validate the custom login URL
            if (!McpServerConfig::isValidCustomLoginUrl($serviceConfig['custom_login_url'])) {
                Log::error('MCP OAuth: Invalid custom login URL', [
                    'url' => $serviceConfig['custom_login_url'],
                    'service' => $mcpService,
                ]);
                return $this->errorResponse('server_error', 'Invalid custom login URL configuration');
            }

            // Get available OAuth services to pass to custom login page
            $oauthServices = $this->getAvailableOAuthServices();

            // Redirect to custom login page with OAuth params
            $customLoginUrl = $this->buildRedirectUrl($serviceConfig['custom_login_url'], [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => $authState,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $codeChallengeMethod,
                'scope' => $scope,
                'original_state' => $state,
                'service' => $mcpService,
                'login_url' => "{$baseUrl}/mcp/{$mcpService}/login",
                'oauth_services' => !empty($oauthServices) ? base64_encode(json_encode($oauthServices)) : '',
                'oauth_callback_base' => "{$baseUrl}/mcp/{$mcpService}/oauth-complete",
            ]);

            Log::info('MCP OAuth: Redirecting to custom login page', [
                'custom_url' => $serviceConfig['custom_login_url'],
                'service' => $mcpService,
            ]);

            return redirect($customLoginUrl);
        }

        // ============================================================
        // Default: Server-rendered login page
        // Renders a simple login form that POSTs directly to
        // /mcp/{service}/login, bypassing the Angular SPA entirely.
        // This avoids the race condition where Angular's reactive UI
        // renders a half-authenticated state while window.location
        // navigates away.
        // ============================================================

        $oauthServices = $this->getAvailableOAuthServices();

        return response()->view('mcp::mcp-login', [
            'serviceName' => $mcpService,
            'loginUrl' => "{$baseUrl}/mcp/{$mcpService}/login",
            'state' => $authState,
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'codeChallengeMethod' => $codeChallengeMethod,
            'scope' => $scope,
            'oauthServices' => $oauthServices,
            'oauthRedirectUrl' => "{$baseUrl}/mcp/{$mcpService}/oauth-redirect",
            'error' => $request->query('error'),
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Handle login form submission
     * POST /mcp/{service}/login
     */
    public function login(Request $request, string $mcpService)
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $state = $request->input('state');
        $clientId = $request->input('client_id');
        $redirectUri = $request->input('redirect_uri');
        $codeChallenge = $request->input('code_challenge');
        $codeChallengeMethod = $request->input('code_challenge_method', 'S256');
        $scope = $request->input('scope', 'mcp:tools mcp:resources mcp:prompts');

        if (empty($email) || empty($password)) {
            return $this->loginError($request, $mcpService, 'Email and password are required.');
        }

        // Authenticate against DreamFactory
        try {
            $dfSession = $this->authenticateWithDreamFactory($email, $password);
        } catch (\Exception $e) {
            Log::error('MCP OAuth login failed', ['error' => $e->getMessage()]);
            return $this->loginError($request, $mcpService, 'Invalid email or password.');
        }

        // Generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $clientId,
            'user_id' => $dfSession['id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'scope' => $scope,
            'df_session_token' => $dfSession['session_token'],
            'user_email' => $dfSession['email'],
            'user_name' => $dfSession['name'] ?? null,
        ]);

        // Build redirect URL
        $redirectParams = ['code' => $authCode->code];
        if (!empty($state)) {
            // Get original state from pending authorization
            $pendingKey = 'mcp_oauth_pending_' . $state;
            $pending = cache()->get($pendingKey);
            if ($pending && !empty($pending['state'])) {
                $redirectParams['state'] = $pending['state'];
            }
            cache()->forget($pendingKey);
        }

        $redirectUrl = $this->buildRedirectUrl($redirectUri, $redirectParams);

        return redirect($redirectUrl);
    }

    /**
     * Handle OAuth callback after DreamFactory login
     * GET /mcp/{service}/oauth-callback
     *
     * This is called after user logs in via DreamFactory's main login page.
     * At this point, the user should have a valid DF session.
     */
    public function oauthCallback(Request $request, string $mcpService)
    {
        $authState = $request->query('auth_state');
        $sessionTokenFromUrl = $request->query('session_token'); // Token passed in URL from DF login

        if (empty($authState)) {
            return $this->errorResponse('invalid_request', 'Missing auth_state parameter');
        }

        // Retrieve pending authorization
        $pendingKey = 'mcp_oauth_pending_' . $authState;
        $pending = cache()->get($pendingKey);

        if (!$pending) {
            return $this->errorResponse('invalid_request', 'Authorization session expired. Please try again.');
        }

        // Try to get the session - check URL param first, then cookies/headers
        $existingSession = null;

        // If token passed in URL (from DF login redirect), validate it directly
        if ($sessionTokenFromUrl) {
            try {
                $existingSession = $this->validateDreamFactorySession($sessionTokenFromUrl);
                $existingSession['session_token'] = $sessionTokenFromUrl;
            } catch (\Exception $e) {
                Log::debug('MCP OAuth: Session token from URL validation failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to cookie/header check
        if (!$existingSession) {
            $existingSession = $this->getExistingDfSession($request);
        }

        if (!$existingSession) {
            // User still not authenticated - redirect back to DF login
            $baseUrl = $this->getBaseUrl($request);
            $callbackUrl = "{$baseUrl}/mcp/{$mcpService}/oauth-callback?auth_state={$authState}";
            $dfLoginUrl = $this->getFrontendUrl($request) . "/auth/login?redirect=" . urlencode($callbackUrl);

            return redirect($dfLoginUrl);
        }

        // User is authenticated - generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $pending['client_id'],
            'user_id' => $existingSession['id'],
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'code_challenge_method' => $pending['code_challenge_method'] ?: 'S256',
            'scope' => $pending['scope'] ?? 'mcp:tools mcp:resources mcp:prompts',
            'df_session_token' => $existingSession['session_token'],
            'user_email' => $existingSession['email'],
            'user_name' => $existingSession['name'] ?? $existingSession['first_name'] ?? null,
        ]);

        // Clean up pending authorization
        cache()->forget($pendingKey);

        // Build redirect URL back to the original client
        $redirectParams = ['code' => $authCode->code];
        if (!empty($pending['state'])) {
            $redirectParams['state'] = $pending['state'];
        }

        $redirectUrl = $this->buildRedirectUrl($pending['redirect_uri'], $redirectParams);

        return redirect($redirectUrl);
    }

    /**
     * Handle OAuth completion from DreamFactory OAuth redirect
     * GET /mcp/{service}/oauth-complete
     *
     * This endpoint receives the session_token from DF OAuth redirect
     * and completes the MCP OAuth authorization flow.
     */
    public function oauthComplete(Request $request, string $mcpService)
    {
        $sessionToken = $request->query('session_token');
        $state = $request->query('state');

        if (empty($sessionToken)) {
            return $this->errorResponse('invalid_request', 'Missing session_token from OAuth provider');
        }

        if (empty($state)) {
            return $this->errorResponse('invalid_request', 'Missing state parameter');
        }

        // Retrieve pending authorization from cache
        $pendingKey = 'mcp_oauth_pending_' . $state;
        $pending = cache()->get($pendingKey);

        if (!$pending) {
            return $this->errorResponse('invalid_request', 'Authorization session expired. Please try again.');
        }

        // Validate session token with DreamFactory
        try {
            $dfSession = $this->validateDreamFactorySession($sessionToken);
            $dfSession['session_token'] = $sessionToken;
        } catch (\Exception $e) {
            Log::error('MCP OAuth oauth-complete failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('access_denied', 'Invalid or expired session token');
        }

        // Generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $pending['client_id'],
            'user_id' => $dfSession['id'],
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'code_challenge_method' => $pending['code_challenge_method'] ?? 'S256',
            'scope' => $pending['scope'] ?? 'mcp:tools mcp:resources mcp:prompts',
            'df_session_token' => $sessionToken,
            'user_email' => $dfSession['email'],
            'user_name' => $dfSession['name'] ?? $dfSession['first_name'] ?? null,
        ]);

        // Clean up pending authorization
        cache()->forget($pendingKey);

        // Build redirect URL back to the original client
        $redirectParams = ['code' => $authCode->code];
        if (!empty($pending['state'])) {
            $redirectParams['state'] = $pending['state'];
        }

        $redirectUrl = $this->buildRedirectUrl($pending['redirect_uri'], $redirectParams);

        Log::info('MCP OAuth: OAuth complete, redirecting to client', [
            'redirect_uri' => $pending['redirect_uri'],
            'user_email' => $dfSession['email'],
        ]);

        return redirect($redirectUrl);
    }

    /**
     * Redirect to a DreamFactory OAuth provider (Azure, Google, etc.)
     * GET /mcp/{service}/oauth-redirect
     *
     * Initiates the external OAuth flow by redirecting to the DF OAuth
     * endpoint with a redirect back to /mcp/{service}/oauth-complete.
     */
    public function oauthRedirect(Request $request, string $mcpService)
    {
        $provider = $request->query('provider');
        $state = $request->query('state');

        if (empty($provider) || empty($state)) {
            return $this->errorResponse('invalid_request', 'Missing provider or state');
        }

        $baseUrl = $this->getBaseUrl($request);
        $oauthCompleteUrl = "{$baseUrl}/mcp/{$mcpService}/oauth-complete?state={$state}";

        // Build DF OAuth URL: /api/v2/{provider_path}&redirect={callback}
        $separator = str_contains($provider, '?') ? '&' : '?';
        $oauthUrl = "{$this->dfUrl}/api/v2/{$provider}{$separator}redirect=" . urlencode($oauthCompleteUrl);

        return redirect($oauthUrl);
    }

    /**
     * Handle DreamFactory session token callback
     * POST /mcp/{service}/df-callback
     */
    public function dfCallback(Request $request, string $mcpService)
    {
        $sessionToken = $request->input('session_token');
        $state = $request->input('state');
        $clientId = $request->input('client_id');
        $redirectUri = $request->input('redirect_uri');
        $codeChallenge = $request->input('code_challenge');
        $originalState = $request->input('original_state');
        $scope = $request->input('scope', 'mcp:tools mcp:resources mcp:prompts');

        if (empty($sessionToken)) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'Missing session_token',
            ], 400);
        }

        // Validate session token with DreamFactory
        try {
            $dfSession = $this->validateDreamFactorySession($sessionToken);
        } catch (\Exception $e) {
            Log::error('MCP OAuth df-callback failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Invalid or expired session token',
            ], 401);
        }

        // Generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $clientId,
            'user_id' => $dfSession['id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => $scope,
            'df_session_token' => $sessionToken,
            'user_email' => $dfSession['email'],
            'user_name' => $dfSession['name'] ?? null,
        ]);

        // Build redirect URL
        $redirectParams = ['code' => $authCode->code];
        if (!empty($originalState)) {
            $redirectParams['state'] = $originalState;
        }

        $redirectUrl = $this->buildRedirectUrl($redirectUri, $redirectParams);

        return response()->json([
            'redirect' => $redirectUrl,
        ]);
    }

    /**
     * Token Endpoint
     * POST /mcp/{service}/token
     */
    public function token(Request $request, string $mcpService)
    {
        Log::info('OAuth token request', [
            'grant_type' => $request->input('grant_type'),
            'code' => $request->input('code') ? substr($request->input('code'), 0, 20) . '...' : null,
            'redirect_uri' => $request->input('redirect_uri'),
            'client_id' => $request->input('client_id'),
            'client_secret' => $request->input('client_secret') ? 'present' : 'missing',
            'code_verifier' => $request->input('code_verifier') ? 'present' : 'missing',
        ]);

        $grantType = $request->input('grant_type');

        if ($grantType === 'authorization_code') {
            return $this->handleAuthorizationCodeGrant($request, $mcpService);
        } elseif ($grantType === 'refresh_token') {
            return $this->handleRefreshTokenGrant($request, $mcpService);
        }

        return $this->tokenErrorResponse('unsupported_grant_type', 'Unsupported grant type');
    }


    /**
     * Handle authorization_code grant
     */
    private function handleAuthorizationCodeGrant(Request $request, string $mcpService)
    {
        $code = $request->input('code');
        $redirectUri = $request->input('redirect_uri');
        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');
        $codeVerifier = $request->input('code_verifier');

        if (empty($code)) {
            Log::warning('OAuth token: Missing code');
            return $this->tokenErrorResponse('invalid_request', 'Missing code');
        }

        // Get service config and validate client credentials
        $serviceConfig = $request->attributes->get('mcp_service_config');
        if (!$serviceConfig || empty($serviceConfig['oauth_client_id'])) {
            return $this->tokenErrorResponse('server_error', 'OAuth not configured for this service');
        }

        // Validate client_id
        if (empty($clientId) || $clientId !== $serviceConfig['oauth_client_id']) {
            Log::warning('OAuth token: Invalid client_id', [
                'provided' => $clientId,
                'service' => $mcpService,
            ]);
            return $this->tokenErrorResponse('invalid_client', 'Invalid client_id');
        }

        // Validate client_secret
        if (empty($clientSecret) || $clientSecret !== $serviceConfig['oauth_client_secret']) {
            Log::warning('OAuth token: Invalid client_secret', [
                'service' => $mcpService,
            ]);
            return $this->tokenErrorResponse('invalid_client', 'Invalid client_secret');
        }

        // Find and validate code
        $authCode = McpOAuthAuthorizationCode::findValidCode($code);
        if (!$authCode) {
            Log::warning('OAuth token: Invalid or expired code', ['code' => substr($code, 0, 20) . '...']);
            return $this->tokenErrorResponse('invalid_grant', 'Invalid or expired authorization code');
        }

        Log::info('OAuth token: Found auth code', [
            'stored_client_id' => $authCode->client_id,
            'request_client_id' => $clientId,
            'stored_redirect_uri' => $authCode->redirect_uri,
            'request_redirect_uri' => $redirectUri,
            'has_code_challenge' => !empty($authCode->code_challenge),
        ]);

        // Verify client matches the auth code
        if ($authCode->client_id !== $clientId) {
            Log::warning('OAuth token: Client mismatch with auth code');
            $authCode->consume();
            return $this->tokenErrorResponse('invalid_grant', 'Client mismatch');
        }

        // Verify redirect URI
        if ($authCode->redirect_uri !== $redirectUri) {
            Log::warning('OAuth token: Redirect URI mismatch', [
                'stored' => $authCode->redirect_uri,
                'request' => $redirectUri,
            ]);
            $authCode->consume();
            return $this->tokenErrorResponse('invalid_grant', 'Redirect URI mismatch');
        }

        // Verify PKCE
        if (!empty($authCode->code_challenge) && !$authCode->verifyCodeChallenge($codeVerifier ?? '')) {
            Log::warning('OAuth token: PKCE verification failed');
            $authCode->consume();
            return $this->tokenErrorResponse('invalid_grant', 'Invalid code_verifier');
        }

        // Create access token
        $accessToken = McpOAuthAccessToken::createToken([
            'client_id' => $authCode->client_id,
            'user_id' => $authCode->user_id,
            'df_session_token' => $authCode->df_session_token,
            'user_email' => $authCode->user_email,
            'user_name' => $authCode->user_name,
            'scope' => $authCode->scope ?? 'mcp:tools mcp:resources mcp:prompts',
        ]);

        // Consume the authorization code
        $authCode->consume();

        $tokenResponse = [
            'access_token' => $accessToken->access_token,
            'token_type' => 'Bearer',
            'expires_in' => McpOAuthAccessToken::ACCESS_TOKEN_LIFETIME_HOURS * 3600,
            'refresh_token' => $accessToken->refresh_token,
        ];

        if (!empty($accessToken->scope)) {
            $tokenResponse['scope'] = $accessToken->scope;
        }

        return response()->json($tokenResponse);
    }

    /**
     * Handle refresh_token grant
     */
    private function handleRefreshTokenGrant(Request $request, string $mcpService)
    {
        $refreshToken = $request->input('refresh_token');
        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');

        if (empty($refreshToken)) {
            return $this->tokenErrorResponse('invalid_request', 'Missing refresh_token');
        }

        // Get service config and validate client credentials
        $serviceConfig = $request->attributes->get('mcp_service_config');
        if (!$serviceConfig || empty($serviceConfig['oauth_client_id'])) {
            return $this->tokenErrorResponse('server_error', 'OAuth not configured for this service');
        }

        // Validate client_id
        if (empty($clientId) || $clientId !== $serviceConfig['oauth_client_id']) {
            return $this->tokenErrorResponse('invalid_client', 'Invalid client_id');
        }

        // Validate client_secret
        if (empty($clientSecret) || $clientSecret !== $serviceConfig['oauth_client_secret']) {
            return $this->tokenErrorResponse('invalid_client', 'Invalid client_secret');
        }

        $token = McpOAuthAccessToken::findValidRefreshToken($refreshToken);
        if (!$token) {
            return $this->tokenErrorResponse('invalid_grant', 'Invalid or expired refresh token');
        }

        // Verify client matches token
        if ($token->client_id !== $clientId) {
            return $this->tokenErrorResponse('invalid_grant', 'Client mismatch');
        }

        // Validate DreamFactory session is still valid
        try {
            $this->validateDreamFactorySession($token->df_session_token);
        } catch (\Exception $e) {
            $token->revoke();
            return $this->tokenErrorResponse('invalid_grant', 'DreamFactory session expired');
        }

        // Refresh the token
        $token->refresh();

        $tokenResponse = [
            'access_token' => $token->access_token,
            'token_type' => 'Bearer',
            'expires_in' => McpOAuthAccessToken::ACCESS_TOKEN_LIFETIME_HOURS * 3600,
            'refresh_token' => $token->refresh_token,
        ];

        if (!empty($token->scope)) {
            $tokenResponse['scope'] = $token->scope;
        }

        return response()->json($tokenResponse);
    }

    /**
     * Authenticate with DreamFactory
     */
    private function authenticateWithDreamFactory(string $email, string $password): array
    {
        $client = new Client(['timeout' => 30]);

        // Try user session first
        try {
            $response = $client->post("{$this->dfUrl}/api/v2/user/session", [
                'json' => [
                    'email' => $email,
                    'password' => $password,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            // Fall back to admin session endpoint
            Log::debug('MCP OAuth: User session failed, trying admin session', ['error' => $e->getMessage()]);
        }

        // Try admin session
        $response = $client->post("{$this->dfUrl}/api/v2/system/admin/session", [
            'json' => [
                'email' => $email,
                'password' => $password,
            ],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Validate DreamFactory session token
     */
    private function validateDreamFactorySession(string $token): array
    {
        $client = new Client(['timeout' => 30]);

        $response = $client->get("{$this->dfUrl}/api/v2/user/session", [
            'headers' => [
                'Accept' => 'application/json',
                'X-DreamFactory-Session-Token' => $token,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Check for existing DreamFactory session from various sources
     *
     * This enables SSO - if user is already logged into DreamFactory,
     * they don't need to log in again for MCP OAuth.
     *
     * Checks in order:
     * 1. Authorization header (Bearer token)
     * 2. X-DreamFactory-Session-Token header
     * 3. Session cookie from Laravel session
     * 4. session_token cookie (if DF sets one)
     *
     * @param Request $request
     * @return array|null Session data with 'session_token' key, or null if no valid session
     */
    private function getExistingDfSession(Request $request): ?array
    {
        $sessionToken = null;

        // 1. Check Authorization header (Bearer token)
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $sessionToken = substr($authHeader, 7);
        }

        // 2. Check X-DreamFactory-Session-Token header
        if (!$sessionToken) {
            $sessionToken = $request->header('X-DreamFactory-Session-Token');
        }

        // 3. Check Laravel session for stored DF token
        if (!$sessionToken) {
            $sessionToken = session('df_session_token');
        }

        // 4. Check session_token cookie (if DreamFactory sets one)
        if (!$sessionToken) {
            $sessionToken = $request->cookie('session_token');
        }

        // 5. Check JWT cookie that DreamFactory might set
        if (!$sessionToken) {
            $sessionToken = $request->cookie('jwt_token');
        }

        if (!$sessionToken) {
            return null;
        }

        // Validate the token with DreamFactory
        try {
            $sessionData = $this->validateDreamFactorySession($sessionToken);

            // Add the session token to the response so we can use it later
            $sessionData['session_token'] = $sessionToken;

            return $sessionData;
        } catch (\Exception $e) {
            Log::debug('MCP OAuth: Existing session token validation failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get base URL from request
     *
     * Handles proxies like ngrok by checking X-Forwarded-* headers
     */
    private function getBaseUrl(Request $request): string
    {
        $scheme = $request->header('X-Forwarded-Proto', $request->getScheme());
        $host = $request->header('X-Forwarded-Host', $request->getHost());

        // For proxied requests, don't include port (proxy handles it)
        // Only include port for direct requests with non-standard ports
        if ($request->header('X-Forwarded-Proto')) {
            // Proxied request - don't add port
            return "{$scheme}://{$host}";
        }

        // Direct request - check if we need to include port
        $port = $request->getPort();
        $url = "{$scheme}://{$host}";
        if (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)) {
            $url .= ":{$port}";
        }

        return $url;
    }

    /**
     * Build URL with query parameters
     */
    private function buildRedirectUrl(string $baseUrl, array $params): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . http_build_query($params);
    }

    /**
     * Error response for authorization endpoint
     */
    private function errorResponse(string $error, string $description)
    {
        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], 400);
    }

    /**
     * Re-render the MCP login page with an error message.
     * Used by the POST /mcp/{service}/login handler when authentication fails.
     */
    private function loginError(Request $request, string $mcpService, string $message)
    {
        $baseUrl = $this->getBaseUrl($request);
        $state = $request->input('state', '');
        $oauthServices = $this->getAvailableOAuthServices();

        return response()->view('mcp::mcp-login', [
            'serviceName' => $mcpService,
            'loginUrl' => "{$baseUrl}/mcp/{$mcpService}/login",
            'state' => $state,
            'clientId' => $request->input('client_id', ''),
            'redirectUri' => $request->input('redirect_uri', ''),
            'codeChallenge' => $request->input('code_challenge', ''),
            'codeChallengeMethod' => $request->input('code_challenge_method', 'S256'),
            'scope' => $request->input('scope', 'mcp:tools mcp:resources mcp:prompts'),
            'oauthServices' => $oauthServices,
            'oauthRedirectUrl' => "{$baseUrl}/mcp/{$mcpService}/oauth-redirect",
            'error' => $message,
            'email' => $request->input('email', ''),
        ], 401);
    }

    /**
     * Error response for token endpoint
     */
    private function tokenErrorResponse(string $error, string $description)
    {
        $statusCode = ($error === 'invalid_client') ? 401 : 400;

        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], $statusCode);
    }

    /**
     * Get available OAuth services from DreamFactory environment
     */
    private function getAvailableOAuthServices(): array
    {
        try {
            $client = new Client(['timeout' => 10]);

            $response = $client->get("{$this->dfUrl}/api/v2/system/environment", [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $env = json_decode($response->getBody()->getContents(), true);

            return $env['authentication']['oauth'] ?? [];
        } catch (\Exception $e) {
            Log::debug('MCP OAuth: Failed to fetch OAuth services', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
