<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Support\InternalKey;
use PHPUnit\Framework\TestCase;

/**
 * Shared secret for the PHP -> daemon hop: MCP_INTERNAL_KEY wins, else a key is
 * generated once into storage/framework and reused. Standalone-safe.
 */
class InternalKeyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mcp-key-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testConfiguredKeyWinsAndNothingIsWritten(): void
    {
        $file = $this->dir . '/mcp_internal_key';
        $this->assertSame('from-env', InternalKey::resolve('from-env', $file));
        $this->assertFileDoesNotExist($file);
    }

    public function testGeneratesPersists0600AndReuses(): void
    {
        $file = $this->dir . '/framework/mcp_internal_key'; // directory created on demand
        $key = InternalKey::resolve(null, $file);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame($key, file_get_contents($file));
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame($key, InternalKey::resolve('', $file), 'second call reuses the file');
        $this->assertSame(['mcp_internal_key'], array_values(array_diff(scandir(dirname($file)), ['.', '..'])), 'no temp files left behind');
    }

    public function testExistingFileIsTrimmedAndNeverOverwritten(): void
    {
        mkdir($this->dir);
        $file = $this->dir . '/mcp_internal_key';
        file_put_contents($file, "written-by-another-node\n");
        $this->assertSame('written-by-another-node', InternalKey::resolve(null, $file));
        $this->assertSame("written-by-another-node\n", file_get_contents($file));
    }

    public function testUnwritableLocationReturnsEmpty(): void
    {
        // A regular file where the directory should be: cannot be created, even as root.
        file_put_contents($this->dir, 'x');
        $this->assertSame('', InternalKey::resolve(null, $this->dir . '/framework/mcp_internal_key'));
        unlink($this->dir);
    }

    public function testDefaultLocationIsNotTheFilesServiceRoot(): void
    {
        // storage/app is the stock "files" service root: a key there is downloadable.
        $this->assertSame('framework/mcp_internal_key', InternalKey::FILE);
        $client = file_get_contents(__DIR__ . '/../../src/Client/McpDaemonClient.php');
        $this->assertStringContainsString('storage_path(InternalKey::FILE)', $client);
        $this->assertStringNotContainsString("storage_path('app/", $client);
    }
}
