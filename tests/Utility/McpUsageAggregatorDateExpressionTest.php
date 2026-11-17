<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use DreamFactory\Core\McpServer\Utility\McpUsageAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Daily bucket expression for /_internal/ai/mcp-usage. Pure static method,
 * so standalone-safe.
 */
class McpUsageAggregatorDateExpressionTest extends TestCase
{
    public function testSqlServerUsesConvertStyle23(): void
    {
        $expr = McpUsageAggregator::dateExpression('sqlsrv');

        $this->assertSame('CONVERT(varchar(10), created_at, 23)', $expr);
        $this->assertStringNotContainsString('DATE_FORMAT', $expr);
    }

    public function testExistingDriversUnchanged(): void
    {
        $this->assertStringContainsString('DATE_FORMAT', McpUsageAggregator::dateExpression('mysql'));
        $this->assertStringContainsString('DATE_FORMAT', McpUsageAggregator::dateExpression('mariadb'));
        $this->assertStringContainsString('DATE_FORMAT', McpUsageAggregator::dateExpression('something-weird'));
        $this->assertStringContainsString('strftime', McpUsageAggregator::dateExpression('sqlite'));
        $this->assertStringContainsString('to_char', McpUsageAggregator::dateExpression('pgsql'));
    }

    public function testAlwaysOperatesOnCreatedAt(): void
    {
        foreach (['mysql', 'sqlite', 'pgsql', 'sqlsrv', 'mariadb'] as $driver) {
            $this->assertStringContainsString(
                'created_at',
                McpUsageAggregator::dateExpression($driver),
                "dateExpression for {$driver} should reference created_at"
            );
        }
    }
}
