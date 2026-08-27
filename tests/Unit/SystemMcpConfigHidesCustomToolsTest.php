<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The System API MCP server never runs custom tools: SystemMcpServerConfig
 * must report `custom_tools => []` and strip the key on set/store so nothing
 * is synced to mcp_custom_tools. Source-level + reflection (no DB needed).
 */
class SystemMcpConfigHidesCustomToolsTest extends TestCase
{
    private string $contents;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/Models/SystemMcpServerConfig.php';
        $this->assertFileExists($path);
        $this->contents = file_get_contents($path);
    }

    public function testExtendsMcpServerConfigAndOverridesConfigHooks(): void
    {
        $this->assertStringContainsString('class SystemMcpServerConfig extends McpServerConfig', $this->contents);

        $ref = new \ReflectionClass(\DreamFactory\Core\McpServer\Models\SystemMcpServerConfig::class);
        $this->assertSame(\DreamFactory\Core\McpServer\Models\McpServerConfig::class, $ref->getParentClass()->getName());

        foreach (['getConfig', 'setConfig', 'storeConfig'] as $method) {
            $m = $ref->getMethod($method);
            $this->assertSame($ref->getName(), $m->getDeclaringClass()->getName(), "$method must be overridden");
            $this->assertTrue($m->isStatic(), "$method must be static like the df-core base");
        }

        // Signatures must match BaseServiceConfigModel exactly.
        $this->assertSame(3, $ref->getMethod('getConfig')->getNumberOfParameters());
        $this->assertSame(3, $ref->getMethod('setConfig')->getNumberOfParameters());
        $this->assertSame(2, $ref->getMethod('storeConfig')->getNumberOfParameters());
    }

    public function testGetConfigAlwaysReturnsEmptyCustomTools(): void
    {
        $start = strpos($this->contents, 'public static function getConfig(');
        $body = substr($this->contents, $start, 400);

        $this->assertStringContainsString("\$config['custom_tools'] = [];", $body);
        $this->assertStringNotContainsString('McpCustomTool::', $body);
        // Must forward late static binding; a non-forwarding BaseServiceConfigModel::getConfig()
        // call would resolve `static` to the abstract base and fatal at runtime.
        $this->assertStringNotContainsString('BaseServiceConfigModel::getConfig(', $this->contents);
        $this->assertStringContainsString('parent::getConfig(', $body);
    }

    public function testSetAndStoreConfigDropCustomTools(): void
    {
        foreach (['setConfig', 'storeConfig'] as $method) {
            $start = strpos($this->contents, "public static function {$method}(");
            $this->assertNotFalse($start);
            $body = substr($this->contents, $start, 300);
            $this->assertStringContainsString("unset(\$config['custom_tools']);", $body, $method);
            $this->assertStringContainsString("parent::{$method}(", $body, $method);
        }
    }

    public function testNoCustomToolSyncInSystemModel(): void
    {
        $this->assertStringNotContainsString('syncCustomTools', $this->contents);
        $this->assertStringNotContainsString('syncToolsForService', $this->contents);
    }
}
