<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Exposed Services / scope_tools only steer the DATA daemon's DB/file tool
 * catalog. The System API MCP server (`system_mcp`) never has such a catalog
 * (SystemMcp::resolveAvailableServices() returns []), so its config model
 * must treat both keys exactly like custom_tools: hidden from the admin
 * schema, stripped on get/set/store, and no empty-exposed-services warning.
 * Source-level, like SystemMcpConfigHidesCustomToolsTest (no DB needed).
 */
class SystemMcpConfigHidesExposedServicesTest extends TestCase
{
    private string $contents;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Models/SystemMcpServerConfig.php';
        $this->assertFileExists($path);
        $this->contents = file_get_contents($path);
    }

    public function testExposedServicesPickerIsHiddenFromTheAdminSchema(): void
    {
        $start = strpos($this->contents, '$schemaHiddenFields');
        $this->assertNotFalse($start, 'subclass must redeclare $schemaHiddenFields');
        $block = substr($this->contents, $start, 300);

        foreach (['exposed_services', 'scope_tools', 'custom_tools', 'app_id', 'disabled_tools'] as $field) {
            $this->assertStringContainsString("'{$field}'", $block, "$field must stay schema-hidden for system_mcp");
        }
    }

    public function testScopingKeysAreStrippedFromGetSetAndStore(): void
    {
        foreach (['getConfig', 'setConfig', 'storeConfig'] as $method) {
            $start = strpos($this->contents, "public static function {$method}(");
            $this->assertNotFalse($start, $method);
            $body = substr($this->contents, $start, 400);
            $this->assertStringContainsString(
                "unset(\$config['exposed_services'], \$config['scope_tools']);",
                $body,
                $method
            );
        }
    }

    public function testEmptyExposedServicesWarningIsSuppressed(): void
    {
        $this->assertStringContainsString(
            'protected static function warnIfEmptyExposed(',
            $this->contents
        );

        // The parent must dispatch the hook with late static binding so this
        // override actually runs.
        $parent = file_get_contents(__DIR__ . '/../../src/Models/McpServerConfig.php');
        $this->assertSame(2, substr_count($parent, 'static::warnIfEmptyExposed('));
        $this->assertStringContainsString('protected static function warnIfEmptyExposed(', $parent);
    }
}
