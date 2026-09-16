<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use DreamFactory\Core\McpServer\Utility\ApiKeyAuth;
use PHPUnit\Framework\TestCase;

/**
 * MCP front-door auth gate (allow_api_key_auth). Pure decision logic — no
 * Laravel. The controller wiring around these decisions is locked by
 * ApiKeyAuthWiringTest.
 */
class ApiKeyAuthTest extends TestCase
{
    private const VALID_KEY = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    // ---------------------------------------------------------------
    // Mechanism selection: Bearer always wins; the flag gates key auth
    // ---------------------------------------------------------------

    public function testBearerWinsWhenApiKeyAuthIsEnabled(): void
    {
        // Even with the flag on (and any API-key headers present), a Bearer
        // credential routes through the unchanged OAuth path.
        $this->assertSame(ApiKeyAuth::MODE_BEARER, ApiKeyAuth::decide('Bearer abc123', true));
    }

    public function testBearerWinsWhenApiKeyAuthIsDisabled(): void
    {
        $this->assertSame(ApiKeyAuth::MODE_BEARER, ApiKeyAuth::decide('Bearer abc123', false));
    }

    public function testFlagOffWithoutBearerIsUnauthorized(): void
    {
        // Default behavior preserved: no Bearer + no opt-in = the existing
        // 401 Bearer-required response, even if the client sent an API key.
        $this->assertSame(ApiKeyAuth::MODE_UNAUTHORIZED, ApiKeyAuth::decide(null, false));
        $this->assertSame(ApiKeyAuth::MODE_UNAUTHORIZED, ApiKeyAuth::decide('', false));
    }

    public function testFlagOnWithoutBearerRoutesToApiKeyValidation(): void
    {
        $this->assertSame(ApiKeyAuth::MODE_API_KEY, ApiKeyAuth::decide(null, true));
    }

    public function testNonBearerAuthorizationSchemesDoNotEnterTheOAuthPath(): void
    {
        // e.g. Basic auth is not a Bearer credential: with the flag on the
        // request falls through to key validation; with the flag off it is
        // rejected — never treated as an OAuth token.
        $this->assertSame(ApiKeyAuth::MODE_API_KEY, ApiKeyAuth::decide('Basic dXNlcjpwdw==', true));
        $this->assertSame(ApiKeyAuth::MODE_UNAUTHORIZED, ApiKeyAuth::decide('Basic dXNlcjpwdw==', false));
    }

    public function testBearerRequiresTheExactPrefix(): void
    {
        $this->assertFalse(ApiKeyAuth::hasBearerAuthorization('bearer abc'));
        $this->assertFalse(ApiKeyAuth::hasBearerAuthorization('Bearer'));
        $this->assertTrue(ApiKeyAuth::hasBearerAuthorization('Bearer x'));
    }

    // ---------------------------------------------------------------
    // The allow_api_key_auth service flag (default off)
    // ---------------------------------------------------------------

    public function testFlagDefaultsToDisabled(): void
    {
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(null));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth([]));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['app_id' => 3]));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => null]));
    }

    public function testFlagAcceptsCommonTruthyRepresentations(): void
    {
        // Model casts deliver a bool, but raw DB/local-config values may be
        // int or string.
        $this->assertTrue(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => true]));
        $this->assertTrue(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => 1]));
        $this->assertTrue(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => '1']));
        $this->assertTrue(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => 'true']));
    }

    public function testFlagRejectsFalsyRepresentations(): void
    {
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => false]));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => 0]));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => '0']));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => 'false']));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['allow_api_key_auth' => '']));
    }

    // ---------------------------------------------------------------
    // Key format gate (64-char hex, before any DB lookup)
    // ---------------------------------------------------------------

    public function testAcceptsSixtyFourCharHexKeys(): void
    {
        $this->assertTrue(ApiKeyAuth::isValidKeyFormat(self::VALID_KEY));
        $this->assertTrue(ApiKeyAuth::isValidKeyFormat(strtoupper(self::VALID_KEY)));
        $this->assertTrue(ApiKeyAuth::isValidKeyFormat(str_repeat('0', 64)));
    }

    public function testRejectsMalformedKeys(): void
    {
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(null));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(''));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(substr(self::VALID_KEY, 0, 63)));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(self::VALID_KEY . 'a'));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(str_repeat('g', 64))); // non-hex
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(str_repeat('0', 63) . ' '));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat("' OR 1=1 --" . str_repeat('a', 53)));
    }

    /**
     * Without the /D modifier, PCRE's $ matches before a final newline, so a
     * key pasted with a trailing "\n" would slip past the format gate.
     */
    public function testRejectsKeysWithTrailingNewline(): void
    {
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(self::VALID_KEY . "\n"));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat(self::VALID_KEY . "\r\n"));
        $this->assertFalse(ApiKeyAuth::isValidKeyFormat("\n" . self::VALID_KEY));
    }

    // ---------------------------------------------------------------
    // App-record gate: exists + active + role assigned
    // ---------------------------------------------------------------

    public function testMissingAppIsRejected(): void
    {
        $this->assertSame(ApiKeyAuth::ERR_APP_NOT_FOUND, ApiKeyAuth::appRejection(null));
    }

    public function testInactiveAppIsRejected(): void
    {
        $this->assertSame(
            ApiKeyAuth::ERR_APP_INACTIVE,
            ApiKeyAuth::appRejection(['is_active' => false, 'role_id' => 2])
        );
        $this->assertSame(
            ApiKeyAuth::ERR_APP_INACTIVE,
            ApiKeyAuth::appRejection(['is_active' => 0, 'role_id' => 2])
        );
    }

    public function testAppWithoutRoleIsRejected(): void
    {
        // No role means nothing scopes key-only access — must reject.
        $this->assertSame(
            ApiKeyAuth::ERR_APP_NO_ROLE,
            ApiKeyAuth::appRejection(['is_active' => true, 'role_id' => null])
        );
        $this->assertSame(
            ApiKeyAuth::ERR_APP_NO_ROLE,
            ApiKeyAuth::appRejection(['is_active' => true, 'role_id' => 0])
        );
        $this->assertSame(
            ApiKeyAuth::ERR_APP_NO_ROLE,
            ApiKeyAuth::appRejection(['is_active' => true])
        );
    }

    public function testActiveAppWithRolePasses(): void
    {
        $this->assertNull(ApiKeyAuth::appRejection(['is_active' => true, 'role_id' => 7]));
        $this->assertNull(ApiKeyAuth::appRejection(['is_active' => 1, 'role_id' => '7']));
    }

    public function testInactiveCheckPrecedesRoleCheck(): void
    {
        // An inactive app is reported as inactive even when it also lacks a
        // role: activation status is the stronger gate.
        $this->assertSame(
            ApiKeyAuth::ERR_APP_INACTIVE,
            ApiKeyAuth::appRejection(['is_active' => false, 'role_id' => null])
        );
    }
}
