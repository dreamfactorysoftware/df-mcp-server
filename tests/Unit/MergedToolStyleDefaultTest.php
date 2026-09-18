<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Merged database tools are the default for NEW MCP services only (#65).
 * Existing rows keep whatever tool_style they have (null reads as prefixed
 * in the daemon), so the migration must not backfill and the model must
 * only fill the value on create. Source-level: the package cannot boot
 * Laravel standalone.
 */
class MergedToolStyleDefaultTest extends TestCase
{
    private string $model;
    private string $migration;

    protected function setUp(): void
    {
        $this->model = file_get_contents(__DIR__ . '/../../src/Models/McpServerConfig.php');
        $this->migration = file_get_contents(
            __DIR__ . '/../../database/migrations/2026_09_17_000000_add_tool_style_to_mcp_server_config.php'
        );
    }

    public function testCreatingHookDefaultsMissingToolStyleToMerged(): void
    {
        $start = strpos($this->model, 'static::creating(function ($model)');
        $this->assertNotFalse($start, 'creating hook must exist');
        $end = strpos($this->model, '});', $start);
        $hook = substr($this->model, $start, $end - $start);

        $this->assertMatchesRegularExpression(
            '/if \(empty\(\$model->tool_style\)\) \{\s*\$model->tool_style = \'merged\';/',
            $hook,
            'creating hook must set tool_style=merged only when the new row has none'
        );

        // Only the creating hook may assign a default; saving/updating must
        // never rewrite an existing row's style.
        $this->assertSame(1, substr_count($this->model, "->tool_style = 'merged'"));
        $this->assertStringNotContainsString('static::saving(', $this->model);
        $this->assertStringNotContainsString('static::updating(', $this->model);
    }

    public function testConfigSchemaDefaultsToMergedAndLabelsPrefixedAsLegacy(): void
    {
        $start = strpos($this->model, "case 'tool_style':");
        $this->assertNotFalse($start);
        $block = substr($this->model, $start, strpos($this->model, 'break;', $start) - $start);

        $this->assertStringContainsString("\$schema['default'] = 'merged';", $block);
        $this->assertStringContainsString("['label' => 'Merged with a service argument (default)', 'name' => 'merged']", $block);
        $this->assertStringContainsString("['label' => 'Prefixed per service (legacy)', 'name' => 'prefixed']", $block);
        $this->assertStringContainsString('Existing services keep Prefixed', $block);
    }

    public function testMigrationLeavesExistingRowsUntouched(): void
    {
        $this->assertStringContainsString("string('tool_style', 16)->nullable()", $this->migration);
        // No backfill/default: a null column is how existing services stay prefixed.
        $this->assertStringNotContainsString('->default(', $this->migration);
        $this->assertStringNotContainsString('DB::', $this->migration);
        $this->assertStringNotContainsString('->update(', $this->migration);
    }
}
