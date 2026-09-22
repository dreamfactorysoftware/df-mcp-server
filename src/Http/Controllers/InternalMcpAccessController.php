<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Models\McpServerConfig;
use DreamFactory\Core\McpServer\Utility\McpUsageAggregator;
use DreamFactory\Core\McpServer\Utility\RoleAccessGate;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only: "who can connect" data for one MCP service.
 *
 * Returns the roles holding a grant on the service, plus every role seen in
 * mcp_request_log for it during the period, flagged with whether it holds a
 * grant. With require_role_access off this is the list an admin must review
 * before turning it on; with it on, the denied rows show who is knocking.
 */
class InternalMcpAccessController extends Controller
{
    public function access(Request $request): JsonResponse
    {
        if (!Session::isSysAdmin()) {
            return response()->json(['error' => ['message' => 'Admin access required.']], 403);
        }

        $ref = (string) $request->get('service', '');
        $service = is_numeric($ref)
            ? Service::find((int) $ref)
            : Service::where('name', $ref)->first();
        if (!$service) {
            return response()->json(['error' => ['message' => 'MCP service not found.']], 404);
        }

        $period = (string) $request->get('period', '30d');
        $since = McpUsageAggregator::parsePeriod($period);

        $config = McpServerConfig::whereServiceId($service->id)->first();
        $granted = RoleAccessGate::grantedRoleIds((int) $service->id);

        $seen = DB::table('mcp_request_log')
            ->where('service_id', $service->id)
            ->where('created_at', '>=', $since)
            ->whereNotNull('role_id')
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as requests, max(created_at) as last_seen, sum(case when status = ? then 1 else 0 end) as denied', ['denied'])
            ->get()
            ->keyBy('role_id');

        $roleIds = array_values(array_unique(array_merge($granted, $seen->keys()->map(fn ($k) => (int) $k)->all())));
        $names = DB::table('role')->whereIn('id', $roleIds)->pluck('name', 'id');

        $roles = [];
        foreach ($roleIds as $roleId) {
            $row = $seen->get($roleId);
            $roles[] = [
                'role_id'   => $roleId,
                'name'      => $names[$roleId] ?? "#{$roleId}",
                'granted'   => in_array($roleId, $granted, true),
                'requests'  => $row ? (int) $row->requests : 0,
                'denied'    => $row ? (int) $row->denied : 0,
                'last_seen' => $row ? (string) $row->last_seen : null,
            ];
        }
        usort($roles, fn ($a, $b) => [$b['requests'], $a['name']] <=> [$a['requests'], $b['name']]);

        return response()->json([
            'service_id'          => (int) $service->id,
            'service'             => $service->name,
            'require_role_access' => $config ? (bool) $config->require_role_access : false,
            'period'              => $period,
            'roles'               => $roles,
        ]);
    }
}
