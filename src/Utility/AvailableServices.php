<?php

namespace DreamFactory\Core\McpServer\Utility;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use Illuminate\Support\Facades\Log;

/**
 * Role-filtered DB + file catalog the daemon auto-exposes as tools, optionally
 * scoped to the connected MCP service.
 *
 * Default: only names in the MCP service's `exposed_services` config. An
 * empty list means no auto-generated DB/file tools (custom tools still work).
 * `MCP_SCOPE_TOOLS=false` (or per-service `scope_tools=false`) restores the
 * instance-wide catalog when the list is also empty. An explicit list always
 * applies, even when the flag is off.
 */
class AvailableServices
{
    /**
     * Resolve the tool-catalog services for one MCP connection.
     *
     * @param  array<string, mixed>  $mcpConfig  MCP service config (exposed_services, scope_tools)
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(string $mcpServiceName, array $mcpConfig = []): array
    {
        try {
            $services = self::catalog();
            $scopeByDefault = filter_var(config('mcp.scope_tools', true), FILTER_VALIDATE_BOOLEAN);
            $scoped = self::scope($services, $mcpConfig, $scopeByDefault);

            $exposed = self::names($mcpConfig['exposed_services'] ?? null);
            $explicit = self::boolOrNull($mcpConfig['scope_tools'] ?? null);
            $scoping = $exposed !== []
                || $explicit === true
                || ($explicit === null && $scopeByDefault);
            if ($scoping && $scoped === []) {
                Log::info('MCP tools/list scoped to an empty backend catalog', [
                    'mcp_service' => $mcpServiceName,
                    'hint' => 'Set exposed_services to the database/file service names this MCP endpoint should wrap',
                ]);
            }

            return $scoped;
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve available services for MCP daemon', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Filter a role-filtered catalog down to `exposed_services`.
     *
     * Pure: no Laravel, no I/O. Safe to unit-test.
     *
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<string, mixed>  $mcpConfig
     * @return array<int, array<string, mixed>>
     */
    public static function scope(
        array $services,
        array $mcpConfig = [],
        bool $scopeByDefault = false
    ): array {
        $exposed = self::names($mcpConfig['exposed_services'] ?? null);
        $explicit = self::boolOrNull($mcpConfig['scope_tools'] ?? null);
        $scoping = $exposed !== []
            || $explicit === true
            || ($explicit === null && $scopeByDefault);

        if (!$scoping) {
            return array_values($services);
        }

        $allowed = array_values(array_unique($exposed));
        if ($allowed === []) {
            return [];
        }

        $allowedLower = array_map('strtolower', $allowed);

        return array_values(array_filter(
            $services,
            static fn ($s) => in_array(strtolower((string) ($s['name'] ?? '')), $allowedLower, true)
        ));
    }

    /**
     * Database + file services visible to the current session's role.
     *
     * Sysadmins see every service; non-admins are filtered by role.services —
     * the same pattern as Service::getUserAccessibleServices().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalog(): array
    {
        /** @var \DreamFactory\Core\Services\ServiceManager $serviceManager */
        $serviceManager = app('df.service');
        $fields = ['id', 'name', 'label', 'type'];

        $services = array_merge(
            array_map(
                static fn ($s) => array_merge($s, ['category' => 'database']),
                $serviceManager->getServiceListByGroup(ServiceTypeGroups::DATABASE, $fields, true)
            ),
            array_map(
                static fn ($s) => array_merge($s, ['category' => 'file']),
                $serviceManager->getServiceListByGroup(ServiceTypeGroups::FILE, $fields, true)
            )
        );

        if (!SessionUtilities::isSysAdmin()) {
            $ids = self::accessibleServiceIds();
            if ($ids === null) {
                // null => role grants all services; no filtering.
            } elseif ($ids !== []) {
                $services = array_values(array_filter(
                    $services,
                    static fn ($s) => in_array((int) ($s['id'] ?? 0), $ids, true)
                ));
            } else {
                $services = [];
            }
        }

        return $services;
    }

    /**
     * Service ids the current session's role can reach, or null when the role
     * grants access to every service.
     *
     * @return int[]|null
     */
    public static function accessibleServiceIds(): ?array
    {
        $roleServices = (array) SessionUtilities::get('role.services');
        $ids = [];

        foreach ($roleServices as $access) {
            $sid = is_array($access) ? ($access['service_id'] ?? null) : null;
            if ($sid === null || $sid === 0 || $sid === '') {
                return null;
            }
            if (is_numeric($sid) && $sid > 0) {
                $ids[] = (int) $sid;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return string[]
     */
    public static function names(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                $value = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $trimmed);
            } else {
                $value = preg_split('/\s*,\s*/', $trimmed);
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $names = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $names[] = trim($item);
            } elseif (is_array($item) && isset($item['name']) && is_string($item['name']) && $item['name'] !== '') {
                $names[] = trim($item['name']);
            }
        }

        return array_values(array_unique(array_filter($names, static fn ($n) => $n !== '')));
    }

    public static function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
