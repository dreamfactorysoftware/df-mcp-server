<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\Enums\VerbsMask;
use DreamFactory\Core\Http\Controllers\Controller;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\McpServer\Enums\McpServiceTypes;
use DreamFactory\Core\McpServer\Support\DaemonTarget;
use DreamFactory\Core\McpServer\Support\RoleSession;
use DreamFactory\Core\McpServer\Utility\AvailableServices;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Utility\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only catalog preview (issue #64): what tools/list would return from an
 * MCP service for a chosen role — or an API key's app role — before anyone
 * connects. No MCP session, no daemon session, no audit row, nothing executed.
 *
 * The daemon owns the verb lists, prefix rules, aggregator thresholds, facade
 * names and the lazy decision, so it computes the list (POST
 * /mcp/catalog/preview). PHP contributes what the proxy would have sent for
 * that role — the service config and the role-scoped backend catalog from the
 * shared AvailableServices helper — plus the role's verb mask per backend, so
 * the UI can mark tools that are advertised but denied at call time.
 */
class InternalMcpCatalogController extends Controller
{
    private const LAZY_MODES = ['auto', 'on', 'off'];

    public function preview(Request $request): JsonResponse
    {
        if (!Session::isSysAdmin()) {
            return $this->fail(403, 'Admin access required.');
        }

        $service = $this->mcpService((string) $request->get('service', ''));
        if ($service === null) {
            return $this->fail(404, 'MCP service not found.');
        }
        if ($service->getType() !== McpServiceTypes::DATA) {
            return $this->fail(400, 'Only data-plane MCP services (type "mcp") have a backend tool catalog to preview.');
        }

        $appId = $request->get('app_id');
        $appId = is_numeric($appId) ? (int) $appId : null;
        $roleId = $request->get('role_id');
        $roleId = is_numeric($roleId) ? (int) $roleId : null;
        if ($appId !== null) {
            // An API-key client runs under its app's default role (ApiKeyAuth
            // rejects keys whose app has none), so preview that role.
            try {
                $roleId = (int) (App::getCachedInfo($appId, 'role_id') ?? 0) ?: null;
            } catch (\Throwable $e) {
                return $this->fail(404, 'App not found.');
            }
            if ($roleId === null) {
                return $this->fail(400, 'That app has no default role; API-key clients using it are rejected.');
            }
        }
        if ($roleId === null) {
            return $this->fail(400, 'role_id or app_id is required.');
        }

        try {
            $roleInfo = Role::getCachedInfo($roleId);
        } catch (\Throwable $e) {
            $roleInfo = null;
        }
        if (!is_array($roleInfo) || empty($roleInfo['id'])) {
            return $this->fail(404, 'Role not found.');
        }

        $target = DaemonTarget::forServiceType($service->getType());
        if (!$target['enabled']) {
            return $this->fail(503, $target['disabled_message']);
        }

        $config = $service->getConfig();
        $config = is_array($config) ? $config : [];
        $lazyMode = (string) $request->get('lazy_mode', '');
        $lazyMode = in_array($lazyMode, self::LAZY_MODES, true) ? $lazyMode : null;
        $clientName = trim((string) $request->get('client', '')) ?: 'df-admin-preview';

        // Resolve the catalog and the verb masks AS the previewed role, then
        // hand the admin their own session back.
        [$availableServices, $backends] = RoleSession::run($roleInfo, $appId, function () use ($service, $config): array {
            $available = AvailableServices::resolve($service->getName(), $config);
            $backends = array_map(static function (array $s): array {
                // false when the role is inactive: no verbs at all.
                $mask = Session::getServicePermissions($s['name']);

                return [
                    'name'     => $s['name'],
                    'type'     => $s['type'] ?? null,
                    'category' => $s['category'] ?? 'database',
                    'verbs'    => VerbsMask::maskToArray(is_int($mask) ? $mask : 0),
                ];
            }, $available);

            return [$available, $backends];
        });

        $preview = (new McpDaemonClient($target['url']))->catalogPreview(
            $service->getName(), $config, $availableServices, $clientName, $lazyMode
        );
        if (isset($preview['error'])) {
            return $this->fail((int) ($preview['status'] ?? 502), (string) $preview['error']);
        }

        return response()->json([
            'service'    => ['id' => $service->getServiceId(), 'name' => $service->getName(), 'type' => $service->getType()],
            'role'       => ['id' => (int) $roleInfo['id'], 'name' => $roleInfo['name'] ?? null, 'is_active' => (bool) ($roleInfo['is_active'] ?? true)],
            'app_id'     => $appId,
            'client'     => $clientName,
            'lazy_mode'  => $lazyMode ?? ($config['lazy_mode'] ?? 'auto'),
            'tool_style' => $config['tool_style'] ?? 'prefixed',
            'backends'   => $backends,
        ] + $preview);
    }

    /** Look an MCP service up by name or numeric id; null when it does not exist. */
    private function mcpService(string $ref): ?object
    {
        if ($ref === '') {
            return null;
        }
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $manager */
            $manager = app('df.service');
            $service = ctype_digit($ref) ? $manager->getServiceById((int) $ref) : $manager->getService($ref);
        } catch (\Throwable $e) {
            return null;
        }

        return is_object($service) && method_exists($service, 'getType') ? $service : null;
    }

    private function fail(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => ['message' => $message]], $status);
    }
}
