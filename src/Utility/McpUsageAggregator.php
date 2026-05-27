<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Utility;

use DreamFactory\Core\McpServer\Models\McpRequestLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Aggregates rows from the mcp_request_log table for the Gateway dashboard's
 * MCP section. Mirrors the shape of df-ai's UsageAggregator so the frontend
 * can render both data sources through the same panel components.
 *
 * IMPORTANT: MCP token cost is borne by the calling AI agent (Claude Desktop,
 * Cursor, etc.), NOT by DreamFactory. There is no cost_usd column. The
 * dashboard surfaces request volume / bytes / tool-mix only.
 */
class McpUsageAggregator
{
    public const FILTER_KEYS = [
        'service_id',
        'user_id',
        'role_id',
        'app_id',
        'client_id',
        'method',
        'tool_name',
        'status',
    ];

    public static function parsePeriod(string $period, ?Carbon $now = null): Carbon
    {
        $now = $now ?? Carbon::now();
        if (preg_match('/^(\d+)d$/', $period, $m)) {
            return $now->copy()->subDays((int) $m[1]);
        }
        if (preg_match('/^(\d+)h$/', $period, $m)) {
            return $now->copy()->subHours((int) $m[1]);
        }
        return $now->copy()->subDays(7);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public static function aggregate(Carbon $since, string $driver = 'mysql', array $filters = []): array
    {
        $base = McpRequestLog::query()->where('created_at', '>=', $since);
        self::applyFilters($base, $filters);

        $totalRequests = (clone $base)->count();
        $totalBytesIn = (int) (clone $base)->sum('bytes_in');
        $totalBytesOut = (int) (clone $base)->sum('bytes_out');
        $errorCount = (clone $base)->where('status', 'error')->count();
        $avgDuration = (int) round((float) (clone $base)->avg('duration_ms'));

        $byService = (clone $base)
            ->selectRaw('service_id, COUNT(*) as requests, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out, AVG(duration_ms) as avg_duration')
            ->groupBy('service_id')
            ->get()
            ->toArray();

        $byUser = (clone $base)
            ->selectRaw('user_id, COUNT(*) as requests, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('user_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byRole = (clone $base)
            ->selectRaw('role_id, COUNT(*) as requests, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('role_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byApp = (clone $base)
            ->selectRaw('app_id, COUNT(*) as requests, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('app_id')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byClient = (clone $base)
            ->selectRaw('client_id, client_name, COUNT(*) as requests, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('client_id', 'client_name')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byTool = (clone $base)
            ->whereNotNull('tool_name')
            ->selectRaw('tool_name, COUNT(*) as requests, SUM(bytes_in) as bytes_in, SUM(bytes_out) as bytes_out')
            ->groupBy('tool_name')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $byMethod = (clone $base)
            ->selectRaw('method, COUNT(*) as requests')
            ->groupBy('method')
            ->orderByDesc('requests')
            ->get()
            ->toArray();

        $dateExpr = self::dateExpression($driver);
        $series = (clone $base)
            ->selectRaw(
                "$dateExpr as date, COUNT(*) as requests, "
                . 'SUM(bytes_in) as bytes_in, '
                . 'SUM(bytes_out) as bytes_out, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as errors',
                ['error']
            )
            ->groupBy(\DB::raw($dateExpr))
            ->orderBy(\DB::raw($dateExpr))
            ->get()
            ->toArray();

        return [
            'since'           => $since->toIso8601String(),
            'total_requests'  => $totalRequests,
            'total_bytes_in'  => $totalBytesIn,
            'total_bytes_out' => $totalBytesOut,
            'errors'          => $errorCount,
            'avg_duration_ms' => $avgDuration,
            'by_service'      => $byService,
            'by_user'         => $byUser,
            'by_role'         => $byRole,
            'by_app'          => $byApp,
            'by_client'       => $byClient,
            'by_tool'         => $byTool,
            'by_method'       => $byMethod,
            'series'          => $series,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private static function applyFilters(Builder $query, array $filters): void
    {
        foreach (self::FILTER_KEYS as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $values = $filters[$key];
            if ($values === null || $values === '' || $values === []) {
                continue;
            }
            $query->whereIn($key, is_array($values) ? array_values($values) : [$values]);
        }
    }

    public static function dateExpression(string $driver): string
    {
        if ($driver === 'sqlite') {
            return "strftime('%Y-%m-%d', created_at)";
        }
        if ($driver === 'pgsql') {
            return "to_char(created_at, 'YYYY-MM-DD')";
        }
        return "DATE_FORMAT(created_at, '%Y-%m-%d')";
    }
}
