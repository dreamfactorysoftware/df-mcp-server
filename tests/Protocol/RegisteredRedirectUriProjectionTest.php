<?php

namespace DreamFactory\Core\McpServer\Tests\Protocol;

use PHPUnit\Framework\TestCase;

/**
 * The service config exposes two redirect-URI lists to the admin UI:
 *
 *   redirect_uris             editable  — the admin-managed allowlist
 *   registered_redirect_uris  read-only — what clients registered via RFC 7591 DCR
 *
 * Both are enforced at /authorize. Because register() merges the configured URIs
 * into the OAuth client row, the read-only projection must subtract the configured
 * ones, or every admin-managed URI shows up twice in the UI — once editable and
 * once read-only.
 *
 * The projection itself reads the mcp_oauth_client table, so only its pure parts
 * are asserted behaviourally here; the subtraction is checked structurally.
 */
class RegisteredRedirectUriProjectionTest extends TestCase
{
    private string $modelSrc;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Models/McpServerConfig.php';
        $this->assertFileExists($path);
        $this->modelSrc = file_get_contents($path);
    }

    public function testProjectionSubtractsConfiguredUris(): void
    {
        $this->assertMatchesRegularExpression(
            '/array_diff\s*\(/',
            $this->modelSrc,
            'getRegisteredRedirectUris() must subtract the admin-configured URIs, '
            . 'otherwise register() merging them into the client row makes the UI list them twice.'
        );
    }

    public function testProjectionIsNeverWrittenBack(): void
    {
        $this->assertSame(
            2,
            substr_count($this->modelSrc, "unset(\$config['registered_redirect_uris']);"),
            'Both setConfig() and storeConfig() must drop the read-only projection so it '
            . 'is never persisted onto the config row.'
        );
    }

    public function testNormalizeRejectsUnsafeAndDuplicateEntries(): void
    {
        $class = \DreamFactory\Core\McpServer\Models\McpServerConfig::class;
        if (!class_exists($class)) {
            $this->markTestSkipped('McpServerConfig requires df-core to be autoloadable');
        }

        $this->assertSame(
            ['https://callback.mistral.ai/cb', 'http://localhost:8080/cb'],
            $class::normalizeRedirectUris([
                'https://callback.mistral.ai/cb',
                '   ',
                'javascript:alert(1)',
                'file:///etc/passwd',
                'not-a-url',
                'http://localhost:8080/cb',
                'https://callback.mistral.ai/cb',
            ])
        );
    }
}
