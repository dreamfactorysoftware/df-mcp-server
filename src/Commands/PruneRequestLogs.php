<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Commands;

use DreamFactory\Core\McpServer\Models\McpRequestLog;
use Illuminate\Console\Command;

/**
 * Drops mcp_request_log rows older than the configured retention window.
 * Mirrors df-ai's `ai:prune-usage-logs` so customers can schedule both with
 * the same cron pattern.
 *
 * Run manually:  php artisan mcp:prune-request-logs
 * Run scheduled: register $schedule->command('mcp:prune-request-logs')->daily()
 *                in the app's Console\Kernel (not done in this package — the
 *                operating customer chooses when to prune).
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
