<?php

namespace DreamFactory\Core\McpServer\Http\Controllers;

use Carbon\Carbon;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Models\McpOAuthAuthorizationCode;
use DreamFactory\Core\McpServer\Models\McpOAuthClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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
    private string $dfFrontendUrl;

    public function __construct()
    {
        $this->dfUrl = rtrim(config('app.url', env('DF_URL', 'http://localhost')), '/');
        // Frontend URL for login redirects - defaults to env or hardcoded for dev
        $this->dfFrontendUrl = rtrim(env('DF_FRONTEND_URL', 'https://e4598d73a972.ngrok-free.app/dreamfactory/dist/#'), '/');
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
        ])->withHeaders($this->corsHeaders());
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
        ])->withHeaders($this->corsHeaders());
    }

    /**
     * Dynamic Client Registration (RFC 7591)
     * POST /mcp/{service}/register
     */
    public function register(Request $request, string $mcpService)
    {
        $data = $request->all();

        // Auto-generate client_id if not provided
        if (empty($data['client_id'])) {
            $data['client_id'] = McpOAuthClient::generateClientId();
        }

        $client = McpOAuthClient::registerClient($data);

        return response()->json([
            'client_id' => $client->client_id,
            'client_secret' => $client->getAttributes()['client_secret'], // Bypass $hidden
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
        ])->withHeaders($this->corsHeaders());
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

        // Validate response_type
        if ($responseType !== 'code') {
            return $this->errorResponse('unsupported_response_type', 'Only code response type is supported');
        }

        // Validate client
        if (empty($clientId)) {
            return $this->errorResponse('invalid_request', 'Missing client_id');
        }

        $client = McpOAuthClient::findByClientId($clientId);
        if (!$client) {
            // Auto-register for dynamic clients (like Claude/ChatGPT)
            $client = McpOAuthClient::registerClient([
                'client_id' => $clientId,
                'redirect_uris' => $redirectUri ? [$redirectUri] : [],
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
                'df_session_token' => $existingSession['session_token'],
                'user_email' => $existingSession['email'],
                'user_name' => $existingSession['name'] ?? $existingSession['first_name'] ?? null,
                'is_sys_admin' => $existingSession['is_sys_admin'] ?? false,
                'role_id' => $existingSession['role_id'] ?? null,
            ]);

            // Build redirect URL back to the client
            $redirectParams = ['code' => $authCode->code];
            if (!empty($state)) {
                $redirectParams['state'] = $state;
            }

            $redirectUrl = $redirectUri . (strpos($redirectUri, '?') !== false ? '&' : '?') . http_build_query($redirectParams);

            return redirect($redirectUrl);
        }

        // ============================================================
        // No existing session - redirect to DreamFactory login
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
            'service' => $mcpService,
        ], now()->addMinutes(10));

        // Build the callback URL that DF will redirect to after login
        $baseUrl = $this->getBaseUrl($request);
        $callbackUrl = "{$baseUrl}/mcp/{$mcpService}/oauth-callback?auth_state={$authState}";

        // Redirect to DreamFactory's main login page with redirect parameter
        // DF login should redirect back to our callback after successful authentication
        $dfLoginUrl = "{$this->dfFrontendUrl}/auth/login?redirect=" . urlencode($callbackUrl);

        return redirect($dfLoginUrl);
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

        if (empty($email) || empty($password)) {
            return $this->errorResponse('invalid_request', 'Email and password are required');
        }

        // Authenticate against DreamFactory
        try {
            $dfSession = $this->authenticateWithDreamFactory($email, $password);
        } catch (\Exception $e) {
            Log::error('MCP OAuth login failed', ['error' => $e->getMessage()]);
            return $this->errorResponse('access_denied', 'Invalid credentials');
        }

        // Generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $clientId,
            'user_id' => $dfSession['id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'df_session_token' => $dfSession['session_token'],
            'user_email' => $dfSession['email'],
            'user_name' => $dfSession['name'] ?? null,
            'is_sys_admin' => $dfSession['is_sys_admin'] ?? false,
            'role_id' => $dfSession['role_id'] ?? null,
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

        $redirectUrl = $redirectUri . (strpos($redirectUri, '?') !== false ? '&' : '?') . http_build_query($redirectParams);

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
            $dfLoginUrl = "{$this->dfFrontendUrl}/auth/login?redirect=" . urlencode($callbackUrl);

            return redirect($dfLoginUrl);
        }

        // User is authenticated - generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $pending['client_id'],
            'user_id' => $existingSession['id'],
            'redirect_uri' => $pending['redirect_uri'],
            'code_challenge' => $pending['code_challenge'],
            'code_challenge_method' => $pending['code_challenge_method'] ?: 'S256',
            'df_session_token' => $existingSession['session_token'],
            'user_email' => $existingSession['email'],
            'user_name' => $existingSession['name'] ?? $existingSession['first_name'] ?? null,
            'is_sys_admin' => $existingSession['is_sys_admin'] ?? false,
            'role_id' => $existingSession['role_id'] ?? null,
        ]);

        // Clean up pending authorization
        cache()->forget($pendingKey);

        // Build redirect URL back to the original client
        $redirectParams = ['code' => $authCode->code];
        if (!empty($pending['state'])) {
            $redirectParams['state'] = $pending['state'];
        }

        $redirectUrl = $pending['redirect_uri'] . (strpos($pending['redirect_uri'], '?') !== false ? '&' : '?') . http_build_query($redirectParams);

        return redirect($redirectUrl);
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

        if (empty($sessionToken)) {
            return response()->json([
                'error' => 'invalid_request',
                'error_description' => 'Missing session_token',
            ], 400)->withHeaders($this->corsHeaders());
        }

        // Validate session token with DreamFactory
        try {
            $dfSession = $this->validateDreamFactorySession($sessionToken);
        } catch (\Exception $e) {
            Log::error('MCP OAuth df-callback failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'Invalid or expired session token',
            ], 401)->withHeaders($this->corsHeaders());
        }

        // Generate authorization code
        $authCode = McpOAuthAuthorizationCode::createCode([
            'client_id' => $clientId,
            'user_id' => $dfSession['id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'df_session_token' => $sessionToken,
            'user_email' => $dfSession['email'],
            'user_name' => $dfSession['name'] ?? null,
            'is_sys_admin' => $dfSession['is_sys_admin'] ?? false,
            'role_id' => $dfSession['role_id'] ?? null,
        ]);

        // Build redirect URL
        $redirectParams = ['code' => $authCode->code];
        if (!empty($originalState)) {
            $redirectParams['state'] = $originalState;
        }

        $redirectUrl = $redirectUri . (strpos($redirectUri, '?') !== false ? '&' : '?') . http_build_query($redirectParams);

        return response()->json([
            'redirect' => $redirectUrl,
        ])->withHeaders($this->corsHeaders());
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
            'code_verifier' => $request->input('code_verifier') ? 'present' : 'missing',
        ]);

        $grantType = $request->input('grant_type');

        if ($grantType === 'authorization_code') {
            return $this->handleAuthorizationCodeGrant($request);
        } elseif ($grantType === 'refresh_token') {
            return $this->handleRefreshTokenGrant($request);
        }

        return $this->tokenErrorResponse('unsupported_grant_type', 'Unsupported grant type');
    }

    /**
     * Handle CORS preflight
     */
    public function handleOptions(Request $request, string $mcpService)
    {
        return response('', 204)->withHeaders($this->corsHeaders());
    }

    /**
     * Handle authorization_code grant
     */
    private function handleAuthorizationCodeGrant(Request $request)
    {
        $code = $request->input('code');
        $redirectUri = $request->input('redirect_uri');
        $clientId = $request->input('client_id');
        $codeVerifier = $request->input('code_verifier');

        if (empty($code)) {
            Log::warning('OAuth token: Missing code');
            return $this->tokenErrorResponse('invalid_request', 'Missing code');
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

        // Verify client (only if client_id is provided in request)
        if (!empty($clientId) && $authCode->client_id !== $clientId) {
            Log::warning('OAuth token: Client mismatch');
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
            'is_sys_admin' => $authCode->is_sys_admin,
            'role_id' => $authCode->role_id,
            'scope' => $authCode->scope,
        ]);

        // Consume the authorization code
        $authCode->consume();

        return response()->json([
            'access_token' => $accessToken->access_token,
            'token_type' => 'Bearer',
            'expires_in' => McpOAuthAccessToken::ACCESS_TOKEN_LIFETIME_HOURS * 3600,
            'refresh_token' => $accessToken->refresh_token,
            'scope' => $accessToken->scope,
        ])->withHeaders($this->corsHeaders());
    }

    /**
     * Handle refresh_token grant
     */
    private function handleRefreshTokenGrant(Request $request)
    {
        $refreshToken = $request->input('refresh_token');
        $clientId = $request->input('client_id');

        if (empty($refreshToken)) {
            return $this->tokenErrorResponse('invalid_request', 'Missing refresh_token');
        }

        $token = McpOAuthAccessToken::findValidRefreshToken($refreshToken);
        if (!$token) {
            return $this->tokenErrorResponse('invalid_grant', 'Invalid or expired refresh token');
        }

        // Verify client
        if (!empty($clientId) && $token->client_id !== $clientId) {
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

        return response()->json([
            'access_token' => $token->access_token,
            'token_type' => 'Bearer',
            'expires_in' => McpOAuthAccessToken::ACCESS_TOKEN_LIFETIME_HOURS * 3600,
            'refresh_token' => $token->refresh_token,
            'scope' => $token->scope,
        ])->withHeaders($this->corsHeaders());
    }

    /**
     * Authenticate with DreamFactory
     */
    private function authenticateWithDreamFactory(string $email, string $password): array
    {
        $client = new Client(['timeout' => 30]);

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
     * Error response for authorization endpoint
     */
    private function errorResponse(string $error, string $description)
    {
        return response()->json([
            'error' => $error,
            'error_description' => $description,
        ], 400)->withHeaders($this->corsHeaders());
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
        ], $statusCode)->withHeaders($this->corsHeaders());
    }

    /**
     * CORS headers
     */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ];
    }
}
