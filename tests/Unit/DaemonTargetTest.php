<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use DreamFactory\Core\McpServer\Support\DaemonTarget;
use PHPUnit\Framework\TestCase;

/**
 * DaemonTarget is the single decision point for "which Node daemon serves
 * this MCP service type". `system_mcp` must go to df-system-mcp-server
 * (config mcp.system_daemon.*); every other type keeps the bundled data
 * daemon (config mcp.daemon.*). Config is injected so no app is needed.
 */
class DaemonTargetTest extends TestCase
{
    private function mcpConfig(): array
    {
        return [
            'daemon' => [
                'enabled' => true,
                'url'     => 'http://data-daemon:8006/',
            ],
            'system_daemon' => [
                'enabled' => true,
                'url'     => 'http://df-system-mcp:3700',
            ],
        ];
    }

    public function testSystemTypeResolvesToSystemDaemon(): void
    {
        $target = DaemonTarget::forServiceType('system_mcp', $this->mcpConfig());

        $this->assertSame('system_mcp', $target['type']);
        $this->assertSame('http://df-system-mcp:3700', $target['url']);
        $this->assertTrue($target['enabled']);
        $this->assertSame('System API MCP daemon', $target['label']);
        $this->assertSame('MCP_SYSTEM_DAEMON_ENABLED', $target['enabled_env']);
        $this->assertStringContainsString('MCP_SYSTEM_DAEMON_ENABLED=true', $target['disabled_message']);
        $this->assertStringContainsString('df-system-mcp-server', $target['disabled_message']);
    }

    public function testDataTypeResolvesToDataDaemonAndTrimsTrailingSlash(): void
    {
        $target = DaemonTarget::forServiceType('mcp', $this->mcpConfig());

        $this->assertSame('mcp', $target['type']);
        $this->assertSame('http://data-daemon:8006', $target['url']);
        $this->assertTrue($target['enabled']);
        $this->assertSame('MCP daemon', $target['label']);
        $this->assertSame('MCP_DAEMON_ENABLED', $target['enabled_env']);
    }

    public function testNullAndUnknownTypesFallBackToDataDaemon(): void
    {
        foreach ([null, '', 'something_else'] as $type) {
            $target = DaemonTarget::forServiceType($type, $this->mcpConfig());
            $this->assertSame('http://data-daemon:8006', $target['url'], var_export($type, true));
            $this->assertSame('mcp', $target['type']);
        }
    }

    public function testEnabledFlagIsPerDaemon(): void
    {
        $config = $this->mcpConfig();
        $config['system_daemon']['enabled'] = false;

        $this->assertFalse(DaemonTarget::forServiceType('system_mcp', $config)['enabled']);
        $this->assertTrue(DaemonTarget::forServiceType('mcp', $config)['enabled']);

        $config['system_daemon']['enabled'] = 'false'; // env() string form
        $this->assertFalse(DaemonTarget::forServiceType('system_mcp', $config)['enabled']);
        $config['system_daemon']['enabled'] = 'true';
        $this->assertTrue(DaemonTarget::forServiceType('system_mcp', $config)['enabled']);
    }

    public function testDefaultsWhenSectionsMissing(): void
    {
        $system = DaemonTarget::forServiceType('system_mcp', []);
        $this->assertSame(DaemonTarget::SYSTEM_DEFAULT_URL, $system['url']);
        $this->assertTrue($system['enabled'], 'system daemon defaults to enabled');

        $data = DaemonTarget::forServiceType('mcp', []);
        $this->assertSame(DaemonTarget::DATA_DEFAULT_URL, $data['url']);
        $this->assertTrue($data['enabled'], 'data daemon defaults to enabled (matches config/mcp.php)');
    }

    public function testEnumHelpers(): void
    {
        $this->assertSame(['mcp', 'system_mcp'], McpServiceTypes::all());
        $this->assertTrue(McpServiceTypes::isSystem('system_mcp'));
        $this->assertFalse(McpServiceTypes::isSystem('mcp'));
        $this->assertFalse(McpServiceTypes::isSystem(null));
    }
}
