<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use PHPUnit\Framework\TestCase;

/**
 * Wiring assertions for MCP API-key authentication (allow_api_key_auth).
 *
 * The pure gate decisions are unit-tested in ApiKeyAuthTest. These lock the
 * call sites: the controller's auth flow, session establishment, daemon
 * forwarding, audit logging, and the daemon's TypeScript side at the source
 * level (behavioral daemon coverage lives in daemon/src/services/auth.test.ts,
 * run via npm test).
 */
class ApiKeyAuthWiringTest extends TestCase
{
    private static function src(string $relative): string
    {
        $path = __DIR__ . '/../../' . $relative;
        self::assertFileExists($path);

        return file_get_contents($path);
    }

    // ---------------------------------------------------------------
    // Controller: mechanism selection and ordering
    // ---------------------------------------------------------------

    public function testControllerRoutesAuthThroughTheSharedDecisionHelper(): void
    {
        $src = self::src('src/Http/Controllers/McpStreamController.php');

        $this->assertStringContainsString('ApiKeyAuth::decide(', $src);
        $this->assertStringContainsString('ApiKeyAuth::allowsApiKeyAuth(', $src);
        $this->assertStringContainsString("request->header('Authorization')", $src);
    }

    public function testBearerPathIsUnchangedAndAlwaysWins(): void
    {
        $src = self::src('src/Http/Controllers/McpStreamController.php');

        // decide() returns MODE_API_KEY only when no Bearer credential is
        // present; every other mode flows into the pre-existing Bearer
        // validator (valid token, or the exact 401 Bearer-required response).
        $this->assertStringContainsString('ApiKeyAuth::MODE_API_KEY', $src);
        $this->assertStringContainsString('validateBearerToken($request)', $src);
        $this->assertStringContainsString("'Unauthorized: Bearer token required'", $src);
        $this->assertStringContainsString('findValidAccessToken', $src);

        // Bearer branch must run before the API-key branch inside decide()'s
        // consumer — locked by the helper's own unit tests plus this ordering:
        $decidePos = strpos($src, 'ApiKeyAuth::decide(');
        $apiKeyValidatorPos = strpos($src, 'private function validateApiKeyAuth');
        $this->assertNotFalse($decidePos);
        $this->assertNotFalse($apiKeyValidatorPos);
        $this->assertLessThan($apiKeyValidatorPos, $decidePos);
    }

    public function testGetRequestsAuthenticateBeforeTheSseDecline(): void
    {
        $src = self::src('src/Http/Controllers/McpStreamController.php');

        // develop declines the server-initiated SSE stream with 405 (#50),
        // but an unauthenticated GET must still receive 401 + WWW-Authenticate
        // (OAuth discovery) — so authentication runs first, for API keys too.
        $handleGet = substr($src, strpos($src, 'function handleGet'), 1200);
        $authPos = strpos($handleGet, 'authenticateRequest');
        $declinePos = strpos($handleGet, 'Method Not Allowed');

        $this->assertNotFalse($authPos, 'handleGet must authenticate');
        $this->assertNotFalse($declinePos, 'handleGet must decline the SSE stream with 405');
        $this->assertLessThan($declinePos, $authPos, 'auth must run before the 405 SSE decline');
    }

    // ---------------------------------------------------------------
    // Controller: API-key validation chain
    // ---------------------------------------------------------------

    public function testApiKeyValidationChainIsComplete(): void
    {
        $src = self::src('src/Http/Controllers/McpStreamController.php');

        // Format gate before the lookup, then app resolution + record gate.
        $body = substr($src, strpos($src, 'private function validateApiKeyAuth'));
        $formatPos = strpos($body, 'ApiKeyAuth::isValidKeyFormat');
        $lookupPos = strpos($body, 'App::getAppIdByApiKey');
        $rejectionPos = strpos($body, 'ApiKeyAuth::appRejection');

        $this->assertNotFalse($formatPos, 'must validate the 64-hex key format');
        $this->assertNotFalse($lookupPos, 'must resolve the app via App::getAppIdByApiKey');
        $this->assertNotFalse($rejectionPos, 'must gate on the app record (active + role)');
        $this->assertLessThan($lookupPos, $formatPos, 'format check must precede the DB lookup');
        $this->assertLessThan($rejectionPos, $lookupPos, 'lookup precedes the app-record gate');

        $this->assertStringContainsString("'is_active' => \$app->is_active", $src);
        $this->assertStringContainsString("'role_id' => \$app->role_id", $src);
    }

    public function testOptionalSessionTokenIsValidatedTheWayDfCoreDoes(): void
    {
        $src = self::src('src/Http/Controllers/McpStreamController.php');

        // df-core has no JWTUtilities::decode — validation must go through
        // JWTAuth (signature/expiry/blacklist) + JWTUtilities::verifyUser
        // (user mapping), exactly like df-core's AuthCheck middleware.
        $this->assertStringContainsString('\\JWTAuth::setToken(', $src);
        $this->assertStringContainsString('\\JWTAuth::getPayload()', $src);
        $this->assertStringContainsString('JWTUtilities::verifyUser(', $src);
        $this->assertStringNotContainsString('JWTUtilities::decode', $src);
        $this->assertStringContainsString("'Unauthorized: Invalid or expired session token'", $src);
    }

    // ---------------------------------------------------------------
    // Controller: session establishment (role-filtered services)
    // ---------------------------------------------------------------

    public function testKeyAuthEstablishesTheSessionFromTheKeysApp(): void
    {
        $src = self::src('src/Http/Controllers/McpStreamController.php');

        // Mirrors df-core AuthCheck for API-key requests: record the key and
        // seed the session from the key's app so role.services (consumed by
        // AvailableServices::catalog() via the shared resolve() helper)
        // reflects the app's role for key-only auth, or the user-app role
        // when layered.
        $this->assertStringContainsString('SessionUtilities::setApiKey(', $src);
        $this->assertStringContainsString("SessionUtilities::setSessionData(\$auth['app_id'], \$auth['user_id'])", $src);
        $this->assertStringContainsString('SessionUtilities::setSessionToken(', $src);

        // OAuth path untouched: config app + token user.
        $this->assertStringContainsString('SessionUtilities::setSessionData($appId, $token->user_id)', $src);

        // The role-filtered catalog now lives in the shared helper (7.7.1
        // integration: reduce-tools moved it out of the controller), and the
        // controller must reach it through resolve() so the session
        // established above is what gets filtered.
        $this->assertStringContainsString('AvailableServices::resolve($mcpService', $src);
        $helper = self::src('src/Utility/AvailableServices.php');
        $this->assertStringContainsString("SessionUtilities::get('role.services')", $helper);
        $this->assertStringContainsString('SessionUtilities::isSysAdmin()', $helper);
    }

    // ---------------------------------------------------------------
    // Audit logging: API-key calls land in mcp_request_log
    // ---------------------------------------------------------------

    public function testAuditLoggingRunsWithANullableTokenModel(): void
    {
        $logger = self::src('src/Utility/RequestLogger.php');
        $this->assertStringContainsString('?McpOAuthAccessToken $token', $logger);
        $this->assertStringContainsString('Session::getCurrentUserId()', $logger);
        $this->assertStringContainsString("Session::get('app.id')", $logger);

        $controller = self::src('src/Http/Controllers/McpStreamController.php');
        // The controller logs with the auth result's token slot, which is
        // null for API-key auth — the session fallbacks above then attribute
        // the row to the key's app/role.
        $this->assertStringContainsString("\$token = \$auth['token']", $controller);
        $this->assertStringContainsString('RequestLogger::log($mcpService, $request, $token, $startNs', $controller);
    }

    // ---------------------------------------------------------------
    // Daemon client: credential forwarding
    // ---------------------------------------------------------------

    public function testProxyForwardsTheAuthResultToTheDaemon(): void
    {
        $controller = self::src('src/Http/Controllers/McpStreamController.php');
        $this->assertStringContainsString('$client->proxyRequest($request, $mcpService, $config, $baseUrl, $auth, $availableServices)', $controller);

        $client = self::src('src/Client/McpDaemonClient.php');
        $this->assertStringContainsString('array $authResult', $client);

        // Scope to proxyRequest: rpcStateless is the separate session-authed
        // first-party bridge and legitimately keeps its unconditional token.
        $rpcPos = strpos($client, 'public function rpcStateless');
        $this->assertNotFalse($rpcPos);
        $proxyBody = substr($client, 0, $rpcPos);

        // Session-token header only when present (key-only auth has none).
        $this->assertStringContainsString("\$authResult['session_token']", $proxyBody);
        $this->assertStringContainsString("if (!empty(\$dfSessionToken)) {", $proxyBody);
        $this->assertStringNotContainsString("'X-DreamFactory-Session-Token' => \$dfSessionToken,", $proxyBody);

        // The caller's own key wins; OAuth keeps the config-app key lookup.
        $this->assertStringContainsString("\$authResult['api_key']", $proxyBody);
        $this->assertStringContainsString('App::getApiKeyByAppId', $proxyBody);
    }

    // ---------------------------------------------------------------
    // Config schema / migration
    // ---------------------------------------------------------------

    public function testConfigFlagIsFillableCastAndLabeled(): void
    {
        $model = self::src('src/Models/McpServerConfig.php');

        $this->assertStringContainsString("'allow_api_key_auth',", $model);
        $this->assertStringContainsString("'allow_api_key_auth' => 'boolean'", $model);
        $this->assertStringContainsString("'Allow API Key Authentication'", $model);
        $this->assertMatchesRegularExpression(
            "/case 'allow_api_key_auth':.*?\\\$schema\\['default'\\] = false;/s",
            $model,
            'admin schema must default the flag to off'
        );
    }

    public function testMigrationAddsTheFlagDefaultedOff(): void
    {
        $migration = self::src('database/migrations/2026_09_16_000000_add_allow_api_key_auth_to_mcp_server_config.php');

        $this->assertStringContainsString("boolean('allow_api_key_auth')->default(false)", $migration);
        $this->assertStringContainsString("hasColumn('mcp_server_config', 'allow_api_key_auth')", $migration);
        $this->assertStringContainsString("dropColumn('allow_api_key_auth')", $migration);
    }

    // ---------------------------------------------------------------
    // CORS: browser clients must be able to send the new headers
    // ---------------------------------------------------------------

    public function testCorsAllowsTheApiKeyHeaders(): void
    {
        $middleware = self::src('src/Http/Middleware/McpStreamMiddleware.php');

        $this->assertMatchesRegularExpression(
            '/Access-Control-Allow-Headers.*X-DreamFactory-API-Key/',
            $middleware
        );
        $this->assertMatchesRegularExpression(
            '/Access-Control-Allow-Headers.*X-DreamFactory-Session-Token/',
            $middleware
        );
    }

    // ---------------------------------------------------------------
    // Daemon (TypeScript source level)
    // ---------------------------------------------------------------

    public function testDaemonAcceptsEitherCredentialOnTheMcpEndpoint(): void
    {
        $server = self::src('daemon/src/server.ts');

        $this->assertStringContainsString('extractAndValidateAuth(req, false)', $server);
        $this->assertStringNotContainsString('if (!dfSessionToken) {', $server);
        $this->assertStringContainsString('authResult.credentials?.sessionToken', $server);
        $this->assertStringContainsString('authResult.credentials?.apiKey', $server);
    }

    public function testDaemonKeepsThe64HexKeyFormatValidation(): void
    {
        $authUtils = self::src('daemon/src/utils/auth.utils.ts');

        $this->assertStringContainsString('/^[a-fA-F0-9]{64}$/', $authUtils);
        $this->assertStringContainsString('isValidApiKeyFormat', $authUtils);
        $this->assertStringContainsString('At least one authentication method required', $authUtils);
    }

    public function testDaemonDfCallsRequireAtLeastOneCredential(): void
    {
        $df = self::src('daemon/src/services/dreamfactory.service.ts');

        $this->assertStringContainsString('sessionToken?: string', $df);
        $this->assertStringContainsString('Either session token or API key is required', $df);
        // Session-token header only when the credential exists.
        $this->assertStringNotContainsString("'X-DreamFactory-Session-Token': auth.sessionToken,", $df);
        $this->assertStringContainsString("if (auth.sessionToken) {", $df);

        $globalTools = self::src('daemon/src/services/global-tools.service.ts');
        $this->assertStringNotContainsString("'X-DreamFactory-Session-Token': auth.sessionToken,", $globalTools);

        $toolUtils = self::src('daemon/src/services/tool-utils.ts');
        $this->assertStringContainsString('if (!sessionToken && !apiKey)', $toolUtils);
    }

    public function testDaemonSessionConfigSupportsKeyOnlyAuth(): void
    {
        $session = self::src('daemon/src/services/session.service.ts');
        $this->assertStringContainsString('sessionToken?: string', $session);

        $utils = self::src('daemon/src/utils/utils.ts');
        // Session updates accept key-only credentials, and store exactly the
        // current request's credential set (no resurrecting a stale token).
        $this->assertStringContainsString('if (!sessionToken && !apiKey)', $utils);
        $this->assertStringContainsString('apiConfigs: existingConfig?.apiConfigs', $utils);
        $this->assertStringNotContainsString('sessionToken ?? existingConfig?.sessionToken', $utils);
    }

    public function testCommittedDaemonDistIncludesTheAuthChanges(): void
    {
        // Production runs node dist/server.js — the committed build must
        // contain the ported auth gate, not just the TypeScript source.
        $dist = self::src('daemon/dist/server.js');
        $this->assertStringContainsString('extractAndValidateAuth', $dist);

        $distAuth = self::src('daemon/dist/utils/auth.utils.js');
        $this->assertStringContainsString('[a-fA-F0-9]{64}', $distAuth);
    }
}
