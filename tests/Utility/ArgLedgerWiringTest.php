<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use PHPUnit\Framework\TestCase;

/**
 * Issue #66: the daemon reports argument-normaliser counters (arg_errors,
 * arg_aliases) in the X-Mcp-Ledger header of every tools/call, in every lazy
 * mode. The request log must persist them, or the per-tool argument-error
 * view has nothing to show.
 */
class ArgLedgerWiringTest extends TestCase
{
    private const COLUMNS = ['arg_errors', 'arg_aliases'];

    public function testRequestLoggerStoresArgCounters(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Utility/RequestLogger.php');
        foreach (self::COLUMNS as $col) {
            $this->assertStringContainsString("'$col'", $src, "RequestLogger must map ledger key $col to a column");
            $this->assertMatchesRegularExpression(
                "/'$col'\s*=>\s*\(int\)\s*\(\\\$ledger\['$col'\]\s*\?\?\s*0\)/",
                $src,
                "$col must be coerced to int with a 0 default so a malformed header cannot break the row"
            );
        }
    }

    public function testModelAllowsMassAssignmentOfArgCounters(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Models/McpRequestLog.php');
        foreach (self::COLUMNS as $col) {
            $this->assertMatchesRegularExpression("/\\\$fillable = \[[^\]]*'$col'/s", $src, "$col must be fillable");
            $this->assertMatchesRegularExpression("/'$col'\s*=>\s*'integer'/", $src, "$col must be cast to integer");
        }
    }

    public function testMigrationAddsArgColumnsWithZeroDefault(): void
    {
        $src = file_get_contents(__DIR__ . '/../../database/migrations/2026_09_18_000000_add_arg_columns_to_mcp_request_log.php');
        foreach (self::COLUMNS as $col) {
            $this->assertMatchesRegularExpression(
                "/integer\('$col'\)->default\(0\)/",
                $src,
                "$col must be an integer column defaulting to 0 (rows from tools/list carry no counters)"
            );
        }
        $this->assertStringContainsString("hasColumn('mcp_request_log', 'arg_errors')", $src, 'migration must be idempotent');
    }

    public function testDaemonSendsArgCountersInEveryMode(): void
    {
        $args = file_get_contents(__DIR__ . '/../../daemon/src/services/args.ts');
        $ledger = file_get_contents(__DIR__ . '/../../daemon/src/services/ledger.ts');
        $this->assertStringContainsString("ledgerBump({ arg_aliases: aliases.length, arg_errors: errors.length })", $args);
        // The header is written by the shared ledger module, which does not depend on a lazy state existing.
        $this->assertStringContainsString("setHeader('X-Mcp-Ledger'", $ledger);
        $this->assertStringNotContainsString('lazy', $ledger);
    }
}
