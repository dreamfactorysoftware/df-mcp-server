<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Commands;

use DreamFactory\Core\McpServer\Models\McpRequestLog;
use Illuminate\Console\Command;

/**
 * Drops mcp_request_log rows older than the configured retention window.
 * Mirrors df-ai's `ai:prune-usage-logs`.
 *
 * Run manually:  php artisan mcp:prune-request-logs [--days=N]
 * Run scheduled: daily, registered by the ServiceProvider when
 *                mcp.audit_logging.enabled is true and retention_days > 0
 *                (MCP_AUDIT_LOGGING_ENABLED / MCP_AUDIT_RETENTION_DAYS). Needs
 *                the standard `* * * * * php artisan schedule:run` cron entry.
 */
class PruneRequestLogs extends Command
{
    protected $signature = 'mcp:prune-request-logs
                            {--days= : Days of audit logs to retain (default from config)}';

    protected $description = 'Delete MCP request audit log entries older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('mcp.audit_logging.retention_days', 90));
        $cutoff = now()->subDays($days);

        $deleted = McpRequestLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} MCP request log entries older than {$days} days.");
        return self::SUCCESS;
    }
}
