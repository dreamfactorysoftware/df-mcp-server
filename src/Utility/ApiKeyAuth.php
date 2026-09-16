<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Utility;

/**
 * Pure decision logic for the MCP front-door authentication gate.
 *
 * The MCP endpoint accepts two client credentials:
 *
 *   1. OAuth Bearer token (Authorization: Bearer ...) — the default and the
 *      only mechanism unless a service opts in to API-key auth. Bearer ALWAYS
 *      wins: when an Authorization: Bearer header is present the request is
 *      routed to the OAuth validator regardless of any API-key headers.
 *   2. Static DreamFactory API key (X-DreamFactory-API-Key), only honored when
 *      the service's `allow_api_key_auth` config flag is enabled. The key must
 *      resolve to an active app that has a role assigned — the app's role then
 *      scopes every downstream call. An optional X-DreamFactory-Session-Token
 *      (DF JWT) may be layered on top to add user identity/RBAC.
 *
 * Everything here is framework-free so the gate logic can be unit tested
 * standalone (see tests/bootstrap.php). The controller supplies the Laravel
 * pieces: header extraction, App/token lookups, session setup and responses.
 */
final class ApiKeyAuth
{
    public const HEADER_API_KEY = 'X-DreamFactory-API-Key';
    public const HEADER_SESSION_TOKEN = 'X-DreamFactory-Session-Token';

    /** Route the request to the OAuth Bearer validator. */
    public const MODE_BEARER = 'bearer';
    /** Route the request to the API-key validator. */
    public const MODE_API_KEY = 'api_key';
    /** No usable mechanism: respond 401 Bearer-required, exactly as before. */
    public const MODE_UNAUTHORIZED = 'unauthorized';

    public const ERR_APP_NOT_FOUND = 'App not found';
    public const ERR_APP_INACTIVE = 'App is not active';
    public const ERR_APP_NO_ROLE = 'App must have a role assigned for API key authentication';

    /**
     * DreamFactory API keys are 64-character hex strings (sha256 output; see
     * App::generateApiKey in df-core). Enforced before any database lookup so
     * malformed or oversized values never reach the cache/DB layer. The same
     * pattern is used by the daemon's auth utils (daemon/src/utils/auth.utils.ts).
     */
    private const API_KEY_PATTERN = '/^[a-fA-F0-9]{64}$/';

    /**
     * Whether the Authorization header carries a Bearer credential.
     */
    public static function hasBearerAuthorization(?string $authorizationHeader): bool
    {
        return !empty($authorizationHeader) && str_starts_with($authorizationHeader, 'Bearer ');
    }

    /**
     * Pick the authentication mechanism for a request.
     *
     * Bearer always wins. API-key auth applies only when the service opted in.
     * Everything else falls through to the pre-existing 401 Bearer-required
     * response, so services that never enable the flag behave exactly as today.
     *
     * @param string|null $authorizationHeader Raw Authorization header value
     * @param bool $allowApiKeyAuth The service's allow_api_key_auth flag
     * @return string One of the MODE_* constants
     */
    public static function decide(?string $authorizationHeader, bool $allowApiKeyAuth): string
    {
        if (self::hasBearerAuthorization($authorizationHeader)) {
            return self::MODE_BEARER;
        }

        if ($allowApiKeyAuth) {
            return self::MODE_API_KEY;
        }

        return self::MODE_UNAUTHORIZED;
    }

    /**
     * Read the allow_api_key_auth flag out of a service config array.
     * Absent/null/false-y means disabled (the migration default).
     */
    public static function allowsApiKeyAuth(?array $config): bool
    {
        if (empty($config)) {
            return false;
        }

        $value = $config['allow_api_key_auth'] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Validate the API key format (64-character hex) before any lookup.
     */
    public static function isValidKeyFormat(?string $apiKey): bool
    {
        return is_string($apiKey) && preg_match(self::API_KEY_PATTERN, $apiKey) === 1;
    }

    /**
     * App-record gate for API-key auth: the app must exist, be active, and
     * have a role assigned (the role is what scopes key-only access).
     *
     * @param array{is_active?: mixed, role_id?: mixed}|null $app Relevant app
     *        attributes, or null when the app record was not found
     * @return string|null A rejection reason (one of the ERR_* constants), or
     *         null when the app is acceptable
     */
    public static function appRejection(?array $app): ?string
    {
        if ($app === null) {
            return self::ERR_APP_NOT_FOUND;
        }

        if (empty($app['is_active'])) {
            return self::ERR_APP_INACTIVE;
        }

        if (empty($app['role_id'])) {
            return self::ERR_APP_NO_ROLE;
        }

        return null;
    }
}
