<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Utility\ApiKeyAuth;
use PHPUnit\Framework\TestCase;

/**
 * The API-key auth gate (allow_api_key_auth) and the system_mcp service type
 * meet in exactly one decided way: the flag lives on McpServerConfig, which
 * SystemMcpServerConfig extends, so a System API MCP endpoint honors the same
 * per-service opt-in with the same default-off. No divergent behavior:
 *
 *  - SystemMcpServerConfig must NOT strip or hide allow_api_key_auth (unlike
 *    custom_tools / exposed_services / scope_tools, which are meaningless
 *    there) — the inherited column, cast, schema toggle and migration default
 *    are the single source of truth, so system_mcp stays OAuth-only until an
 *    admin flips the flag.
 *  - The controller authenticates BEFORE picking the daemon target, so the
 *    gate covers both daemons, and the system daemon dispatch (DaemonTarget +
 *    secret-field manifest) runs for whichever auth mode succeeded.
 */
class SystemMcpApiKeyAuthComposesTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function testSystemConfigInheritsTheFlagUntouched(): void
    {
        $sys = $this->src('src/Models/SystemMcpServerConfig.php');

        // Any mention would mean the subclass started stripping the key from
        // get/set/store or hiding it from the admin schema — a semantic
        // decision this test exists to force out into the open.
        $this->assertStringNotContainsString('allow_api_key_auth', $sys);

        // The inherited definition it relies on.
        $base = $this->src('src/Models/McpServerConfig.php');
        $this->assertStringContainsString("'allow_api_key_auth',", $base);
        $this->assertStringContainsString("'allow_api_key_auth' => 'boolean'", $base);
    }

    public function testAbsentFlagMeansOauthOnlyForASystemShapedConfig(): void
    {
        // A fresh system_mcp config row (post-strip: no exposed_services /
        // scope_tools, empty custom_tools) carries no allow_api_key_auth
        // unless the admin set it — and absent must mean disabled.
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(['custom_tools' => [], 'app_id' => 1]));
        $this->assertFalse(ApiKeyAuth::allowsApiKeyAuth(null));
        $this->assertSame(
            ApiKeyAuth::MODE_UNAUTHORIZED,
            ApiKeyAuth::decide(null, ApiKeyAuth::allowsApiKeyAuth(['custom_tools' => []])),
            'no Bearer + no flag must fall through to the byte-identical 401 path'
        );
    }

    public function testAuthGateRunsBeforeDaemonTargetDispatchForEveryType(): void
    {
        $src = $this->src('src/Http/Controllers/McpStreamController.php');

        // The gate reads the middleware-attached config — for system_mcp that
        // is SystemMcpServerConfig::getConfig() output, flag included.
        $gate = substr($src, strpos($src, 'private function authenticateRequest'));
        $this->assertStringContainsString("attributes->get('mcp_service_config')", $gate);
        $this->assertStringContainsString('ApiKeyAuth::allowsApiKeyAuth(', $gate);

        // Ordering inside processMcpRequest: authenticate, then pick the
        // daemon, then (system only) attach the secret-field manifest — so
        // both daemons sit behind the same gate and the system dispatch runs
        // for OAuth- and key-authenticated requests alike.
        $body = substr($src, strpos($src, 'private function processMcpRequest'));
        $authPos = strpos($body, 'authenticateRequest($request)');
        $targetPos = strpos($body, 'DaemonTarget::forServiceType(');
        $manifestPos = strpos($body, 'withSecretFields(SecretFieldManifest::cached())');

        $this->assertNotFalse($authPos);
        $this->assertNotFalse($targetPos);
        $this->assertNotFalse($manifestPos);
        $this->assertLessThan($targetPos, $authPos, 'auth must precede daemon-target selection');
        $this->assertLessThan($manifestPos, $targetPos, 'target selection precedes the manifest attach');
    }
}
