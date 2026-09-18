<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Server-wide writes switch (issue #67): `mcp_server_config.allow_writes`,
 * default true, must exist as a column, be fillable/cast on the config
 * model, carry a plain-words schema entry, be hidden for system_mcp, and
 * be honoured by the daemon (which drops the write verbs at registration
 * so neither tools/list nor the lazy facade can reach them). Source-level,
 * like the other wiring tests — the package cannot boot Laravel standalone.
 * Behavioural daemon coverage lives in daemon/src/services/writes.test.ts.
 */
class AllowWritesWiringTest extends TestCase
{
    private static function src(string $rel): string
    {
        $path = __DIR__ . '/../../' . $rel;
        self::assertFileExists($path);
        return file_get_contents($path);
    }

    public function testMigrationAddsTheColumnOnlyWithDefaultTrue(): void
    {
        $files = glob(__DIR__ . '/../../database/migrations/*_add_allow_writes_to_mcp_server_config.php');
        $this->assertCount(1, $files, 'exactly one allow_writes migration');
        $src = file_get_contents($files[0]);

        $this->assertStringContainsString("\$table->boolean('allow_writes')->default(true)", $src);
        $this->assertStringContainsString("!Schema::hasColumn('mcp_server_config', 'allow_writes')", $src, 'idempotent');
        $this->assertStringContainsString("\$table->dropColumn('allow_writes')", $src, 'reversible');
        // Column only: no backfill / data statements.
        $this->assertStringNotContainsString('DB::', $src);
        $this->assertStringNotContainsString('->update(', $src);
    }

    public function testConfigModelExposesTheFlagWithAPlainWordsSchema(): void
    {
        $src = self::src('src/Models/McpServerConfig.php');

        $fillable = substr($src, strpos($src, 'protected $fillable'), 500);
        $this->assertStringContainsString("'allow_writes'", $fillable, 'fillable');

        $casts = substr($src, strpos($src, 'protected $casts'), 500);
        $this->assertStringContainsString("'allow_writes' => 'boolean'", $casts, 'boolean cast');

        $case = strpos($src, "case 'allow_writes':");
        $this->assertNotFalse($case, 'schema case');
        $block = substr($src, $case, 800);
        $this->assertStringContainsString("\$schema['label'] = 'Allow writes';", $block);
        $this->assertStringContainsString("\$schema['type'] = 'boolean';", $block);
        $this->assertStringContainsString("\$schema['default'] = true;", $block);
        $this->assertMatchesRegularExpression('/read-only/i', $block, 'description says what off means');

        // Not hidden from the data-plane admin schema.
        $hidden = substr($src, strpos($src, 'protected static $schemaHiddenFields'), 400);
        $this->assertStringNotContainsString("'allow_writes'", $hidden);
    }

    public function testSystemMcpHidesTheFlagFromItsSchema(): void
    {
        $src = self::src('src/Models/SystemMcpServerConfig.php');
        $hidden = substr($src, strpos($src, '$schemaHiddenFields'), 400);
        $this->assertStringContainsString("'allow_writes'", $hidden, 'system daemon has no write verbs to switch off');
    }

    public function testDaemonReadsTheFlagAndDropsWriteVerbsInBothStyles(): void
    {
        $server = self::src('daemon/src/server.ts');
        // The envelope parse lives in utils.ts (parseMcpConfig) since the catalog-preview refactor.
        $this->assertStringContainsString('allow_writes', self::src('daemon/src/utils/utils.ts'), 'daemon reads the flag from the config envelope');
        $this->assertSame(2, substr_count($server, 'lazyMode, toolStyle, allowWrites)'), 'stateless and stateful createServer both pass it');

        $utils = self::src('daemon/src/services/tool-utils.ts');
        $verbs = substr($utils, strpos($utils, 'export const WRITE_VERBS'), 400);
        foreach (['create_records', 'update_records', 'delete_records', 'call_stored_procedure', 'call_stored_function', 'create_file', 'create_folder', 'delete_file'] as $verb) {
            $this->assertStringContainsString("'{$verb}'", $verbs, $verb);
        }

        $tools = self::src('daemon/src/services/tools.service.ts');
        $this->assertStringContainsString('allowWrites ? BASE_TOOLS : BASE_TOOLS.filter(t => !WRITE_VERBS.has(t.name))', $tools);
        // The filtered list feeds BOTH the merged and the prefixed registration paths.
        $this->assertStringContainsString('registerMergedDatabaseTools(server, sessionManager, dbConfigs, disabledTools, tools)', $tools);
        $this->assertStringContainsString('for (const tool of tools) {', $tools);

        $files = self::src('daemon/src/services/file-api.tools.ts');
        $this->assertStringContainsString('allowWrites ? FILE_TOOLS : FILE_TOOLS.filter(t => !WRITE_VERBS.has(t.name))', $files);

        $this->assertStringContainsString('READ-ONLY', self::src('daemon/src/utils/utils.ts'), 'instructions mention read-only');
    }

    public function testRoleDenialsMapToPermissionErrorsBeforeTheAuthenticationBranch(): void
    {
        $src = self::src('daemon/src/services/tool-utils.ts');
        $fn = substr($src, strpos($src, 'export const handleError'), 1500);

        $permission = strpos($fn, "message.includes('User is not authenticated')");
        $auth = strpos($fn, "message.includes('Authentication failed') || message.includes('401')");
        $this->assertNotFalse($permission);
        $this->assertNotFalse($auth);
        $this->assertLessThan($auth, $permission, 'the 401 "User is not authenticated" role denial must be classified before the generic 401 branch');
        $this->assertStringContainsString("message.includes('403')", substr($fn, 0, $auth));
        $this->assertStringContainsString('Permission Error: the session\'s role may not ${operation}', $fn);
    }
}
