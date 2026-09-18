<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Support;

use DreamFactory\Core\Enums\ServiceRequestorTypes;
use DreamFactory\Core\Enums\VerbsMask;
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

    /**
     * What the seeded role can do on one backend service, for the catalog
     * preview's "advertised but denied at call time" marking.
     *
     * Session::getServicePermissions() with no component answers only the
     * service-wide rows ('' or '*'), so a role granted purely at component
     * level (GET on _table/orders/* and _table/customers/*) reads as no verbs
     * at all. Here `verbs` is the UNION of verb masks over every one of the
     * role's rows for the service, any component. `component_scoped` is true
     * when none of those rows is service-wide, and `components` lists the
     * component patterns so the UI can say "limited to orders, customers".
     * A service with no rows of its own falls back to getServicePermissions(),
     * which still honours df-core's "all services" rows.
     *
     * @return array{verbs: string[], component_scoped: bool, components: string[]}
     */
    public static function backendAccess(string $service, bool $roleActive = true): array
    {
        $none = ['verbs' => [], 'component_scoped' => false, 'components' => []];
        if (!$roleActive) {
            return $none;
        }

        $mask = 0;
        $rows = 0;
        $serviceWide = false;
        $components = [];
        foreach ((array) Session::get('role.services') as $row) {
            if (!is_array($row) || strcasecmp($service, (string) ($row['service'] ?? '')) !== 0) {
                continue;
            }
            if (!(ServiceRequestorTypes::API & (int) ($row['requestor_mask'] ?? ServiceRequestorTypes::API))) {
                continue;
            }
            $rows++;
            $mask |= (int) ($row['verb_mask'] ?? 0);
            $component = trim((string) ($row['component'] ?? ''), '/');
            if ($component === '' || $component === '*') {
                $serviceWide = true;
            } elseif (!in_array($component, $components, true)) {
                $components[] = $component;
            }
        }

        if ($rows === 0) {
            $fallback = Session::getServicePermissions($service);
            $mask = is_int($fallback) ? $fallback : 0;
        }

        return [
            'verbs'            => VerbsMask::maskToArray($mask),
            'component_scoped' => $rows > 0 && !$serviceWide,
            'components'       => $components,
        ];
    }
}
