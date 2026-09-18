<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Support;

use DreamFactory\Core\Utility\Session;

/**
 * Run a callback with the DreamFactory session seeded as a given role — the
 * same composition McpStreamController's API-key path establishes through
 * Session::setSessionData(): no user (so isSysAdmin() is false and
 * role.services governs), the app id, and Role::getCachedInfo() for the role.
 * Whatever AvailableServices::resolve() and Session::getServicePermissions()
 * read inside the callback is exactly what a session for that role would see.
 *
 * The caller's own session (the admin running a preview) is snapshotted and
 * restored afterwards, even when the callback throws.
 */
final class RoleSession
{
    /**
     * @param array<string, mixed> $roleInfo Role::getCachedInfo() shape (id, name, role_service_access_by_role_id)
     */
    public static function run(array $roleInfo, ?int $appId, callable $fn): mixed
    {
        $snapshot = \Session::all();
        try {
            // setUserInfo(null) is a no-op in df-core; drop the user explicitly
            // or the admin's is_sys_admin keeps lifting the role filter.
            \Session::forget('user');
            \Session::forget('has_role');
            \Session::put('app.id', $appId);
            Session::setRoleInfo($roleInfo);

            return $fn();
        } finally {
            \Session::flush();
            \Session::put($snapshot);
        }
    }
}
