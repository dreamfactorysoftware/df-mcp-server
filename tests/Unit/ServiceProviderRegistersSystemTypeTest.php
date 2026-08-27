<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level assertions (no app container): the ServiceProvider must
 * register a second ServiceType `system_mcp` in the MCP group, backed by
 * SystemMcpServerConfig and the SystemMcp service class, alongside `mcp`.
 */
class ServiceProviderRegistersSystemTypeTest extends TestCase
{
    private string $contents;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/ServiceProvider.php';
        $this->assertFileExists($path);
        $this->contents = file_get_contents($path);
    }

    public function testRegistersBothServiceTypes(): void
    {
        $this->assertSame(2, substr_count($this->contents, '$df->addType(new ServiceType(['));
        $this->assertStringContainsString("'name'           => McpServiceTypes::DATA,", $this->contents);
        $this->assertStringContainsString("'name'           => McpServiceTypes::SYSTEM,", $this->contents);
    }

    public function testSystemTypeBlockIsCorrectlyWired(): void
    {
        $start = strpos($this->contents, "'name'           => McpServiceTypes::SYSTEM,");
        $this->assertNotFalse($start);
        $block = substr($this->contents, $start, 900);

        $this->assertStringContainsString("'label'          => 'System API MCP Server',", $block);
        $this->assertStringContainsString("'group'          => ServiceTypeGroups::MCP,", $block);
        $this->assertStringContainsString("'config_handler' => SystemMcpServerConfig::class,", $block);
        $this->assertMatchesRegularExpression('/return new SystemMcp\(\$config\);/', $block);
        $this->assertStringContainsString('System API', $block);
    }

    public function testSystemTypeNameConstantIsSystemMcp(): void
    {
        $this->assertSame('system_mcp', \DreamFactory\Core\McpServer\Enums\McpServiceTypes::SYSTEM);
        $this->assertSame('mcp', \DreamFactory\Core\McpServer\Enums\McpServiceTypes::DATA);
    }
}
