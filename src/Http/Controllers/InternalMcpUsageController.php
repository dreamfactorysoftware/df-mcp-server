<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\AI\Utility\FilterRequestParser;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Utility\McpUsageAggregator;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only endpoint that powers the MCP section of the AI Gateway dashboard.
 * Pulled out of ServiceProvider so the handler can be unit-tested.
 *
 * Reuses df-ai's FilterRequestParser so the wire contract for filter query
 * params stays identical between /_internal/ai/usage and
 * /_internal/ai/mcp-usage — same UI code drives both.
 */
class InternalMcpUsageController extends Controller
{
    public function usage(Request $request): JsonResponse
    {
        if (!Session::isSysAdmin()) {
            return response()->json(['error' => ['message' => 'Admin access required.']], 403);
        }

        $period = (string) $request->get('period', '7d');
        $since = McpUsageAggregator::parsePeriod($period);
        $driver = \DB::connection()->getDriverName();
        $filters = FilterRequestParser::parse($request, McpUsageAggregator::FILTER_KEYS);

        $result = McpUsageAggregator::aggregate($since, $driver, $filters);
        $result['period'] = $period;

        return response()->json($result);
    }
}
