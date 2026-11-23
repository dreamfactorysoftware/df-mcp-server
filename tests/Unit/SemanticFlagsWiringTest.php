<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Opt-in semantic flags on mcp_server_config: `semantic_catalog` and
 * `compact_results` (new), beside `semantic_tools`. Boolean, NOT NULL,
 * default false, so an upgraded server behaves exactly as df-mcp-server
 * 1.4.1 until an admin turns one on. The whole config array reaches the
 * daemon (McpDaemonClient `_mcpConfig` / X-Mcp-Config), whose parseMcpConfig
 * reads the same keys. Source-level, like the other wiring tests.
 */
class SemanticFlagsWiringTest extends TestCase
{
    private const NEW_FLAGS = ['semantic_catalog', 'compact_results'];
    private const ALL_FLAGS = ['semantic_tools', 'semantic_catalog', 'compact_results'];

    private static function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        self::assertFileExists($path);
        return file_get_contents($path);
    }

    public function testNewMigrationAddsBothColumnsDefaultFalseAfterSemanticTools(): void
    {
        $files = glob(__DIR__ . '/../../database/migrations/*_add_semantic_catalog_and_compact_results_to_mcp_server_config.php');
        $this->assertCount(1, $files, 'exactly one migration for the two new flags');
        $src = file_get_contents($files[0]);

        $this->assertStringContainsString("\$table->boolean('semantic_catalog')->default(false)->after('semantic_tools')", $src);
        $this->assertStringContainsString("\$table->boolean('compact_results')->default(false)->after('semantic_catalog')", $src);
        foreach (self::NEW_FLAGS as $flag) {
            $this->assertStringContainsString("Schema::hasColumn('mcp_server_config', '{$flag}')", $src, "{$flag}: idempotent");
            $this->assertStringContainsString("dropColumn('{$flag}')", $src, "{$flag}: reversible");
        }
        $this->assertStringNotContainsString('DB::', $src, 'column only');
        $this->assertStringNotContainsString('->update(', $src, 'column only');

        // The applied semantic_tools migration is left alone.
        $applied = self::src('database/migrations/2026_11_19_000000_add_semantic_tools_to_mcp_server_config.php');
        $this->assertStringNotContainsString('semantic_catalog', $applied);
    }

    public function testConfigModelFillsCastsAndDescribesEveryFlag(): void
    {
        $src = self::src('src/Models/McpServerConfig.php');
        $fillable = substr($src, strpos($src, 'protected $fillable'), 600);
        $casts = substr($src, strpos($src, 'protected $casts'), 600);

        $labels = ['semantic_tools' => 'Semantic tools', 'semantic_catalog' => 'Semantic catalog', 'compact_results' => 'Compact results'];
        foreach (self::ALL_FLAGS as $flag) {
            $this->assertStringContainsString("'{$flag}'", $fillable, "{$flag}: fillable");
            $this->assertStringContainsString("'{$flag}' => 'boolean'", $casts, "{$flag}: boolean cast");
            $case = strpos($src, "case '{$flag}':");
            $this->assertNotFalse($case, "{$flag}: schema case");
            $block = substr($src, $case, strpos($src, 'break;', $case) - $case);
            $this->assertStringContainsString("\$schema['label'] = '{$labels[$flag]}';", $block);
            $this->assertStringContainsString("\$schema['type'] = 'boolean';", $block);
            $this->assertStringContainsString("\$schema['default'] = false;", $block);
            $this->assertMatchesRegularExpression('/Off \(default\)/', $block, "{$flag}: description says what off means");
        }
    }

    public function testSystemMcpHidesTheDataDaemonFlags(): void
    {
        $src = self::src('src/Models/SystemMcpServerConfig.php');
        $hidden = substr($src, strpos($src, '$schemaHiddenFields'), 600);
        foreach (self::ALL_FLAGS as $flag) {
            $this->assertStringContainsString("'{$flag}'", $hidden, "{$flag}: the system daemon has no database tools");
        }
    }

    public function testDaemonParsesEveryFlagStrictly(): void
    {
        $utils = self::src('daemon/src/utils/utils.ts');
        $this->assertStringContainsString("[true, 1, '1', 'true'].includes", $utils);
        foreach (self::ALL_FLAGS as $flag) {
            $this->assertStringContainsString("isOn(data.{$flag})", $utils, "{$flag}: read from the config envelope");
        }
    }
}
