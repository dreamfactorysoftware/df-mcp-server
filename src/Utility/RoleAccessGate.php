<?php

namespace DreamFactory\Core\McpServer\Utility;

use DreamFactory\Core\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\DB;

/**
 * "Require role access" switch for an MCP service.
 *
 * With the switch on, a non-admin identity must hold a role_service_access
 * grant on the MCP service itself (any verb, service-wide or `*` component)
 * before the streaming endpoint lets it connect. The role's grants on the
 * exposed backends still decide what it can do once inside; this gate only
 * decides who may connect. Admins always pass.
 */
final class RoleAccessGate
{
    public const CONFIG_KEY = 'require_role_access';

    /** JSON-RPC error code for a refused connection (distinct from -32001 auth). */
    public const ERROR_CODE = -32003;

    public static function requires(?array $config): bool
    {
        return filter_var($config[self::CONFIG_KEY] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Does the CURRENT session (already seeded via Session::setSessionData) hold
     * a grant on this MCP service? Admins always do.
     */
    public static function sessionMayConnect(string $mcpServiceName): bool
    {
        if (Session::isSysAdmin()) {
            return true;
        }

        // GET is the verb every role editor grants first; a service-level or
        // `*` component row satisfies getServicePermissions() for an empty
        // component. exception=false: we render our own JSON-RPC error.
        return (bool) Session::checkServicePermission(
            Verbs::GET,
            $mcpServiceName,
            null,
            ServiceRequestorTypes::API,
            false
        );
    }

    public static function denialMessage(string $mcpServiceName): string
    {
        $role = Session::getRoleId();
        $who = $role ? "role #{$role}" : 'this identity';

        return "Forbidden: {$who} has no access to MCP service '{$mcpServiceName}'. "
            . 'An administrator must grant the role access to this service before it can connect.';
    }

    /**
     * Role ids holding any grant on the given service id (for the admin
     * "who can connect" view). Service-wide or `*` component rows only.
     *
     * @return int[]
     */
    public static function grantedRoleIds(int $serviceId): array
    {
        return DB::table('role_service_access')
            ->where('service_id', $serviceId)
            ->where('verb_mask', '>', 0)
            ->where(function ($q) {
                $q->whereNull('component')->orWhere('component', '')->orWhere('component', '*');
            })
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
