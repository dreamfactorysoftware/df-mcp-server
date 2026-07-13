<?php

namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\McpServer\Client\McpDaemonClient;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use Illuminate\Support\Str;

class Mcp extends BaseRestService
{
    protected function getMcpEndpoint(): string
    {
        return url('/mcp/' . $this->name);
    }

    protected function handleGET()
    {
        if ($this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST)) {
            return ResourcesWrapper::wrapResources($this->getAccessList());
        }

        return [
            'service_id' => $this->id,
            'mcp_endpoint' => $this->getMcpEndpoint(),
        ];
    }

    protected function handlePOST()
    {
        // First-party internal callers (e.g. df-ai-chat) reach the MCP
        // protocol here at POST /api/v2/{service}/rpc, authenticating with a
        // DreamFactory session token + API key rather than the external OAuth
        // Bearer flow. DF's RBAC pipeline has already authenticated this
        // request, so the caller's role — mapped from their LDAP/Entra group
        // at login — is live in the session and governs every downstream
        // tool call the daemon makes on their behalf.
        if ($this->resource === 'rpc') {
            return $this->handleRpcBridge();
        }

        return [
            'ok' => true,
            'message' => 'MCP server configuration updated successfully',
            'server' => [
                'mcp_endpoint' => $this->getMcpEndpoint(),
            ],
        ];
    }

    /**
     * Bridge a JSON-RPC MCP request from a session-authenticated first-party
     * caller to the MCP daemon, reusing the exact proxy the OAuth path uses.
     * The only difference from {@see McpStreamController::processMcpRequest}
     * is the front-door auth: here the DF pipeline has already established the
     * session, so the caller's role scopes the tool surface and the daemon's
     * data calls run under that role (region filters, verb masks, and all).
     */
    protected function handleRpcBridge()
    {
        $request = request();

        $dfSessionToken = (string) ($request->header('X-DreamFactory-Session-Token') ?? '');
        if ($dfSessionToken === '') {
            return response()->json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32001, 'message' => 'Unauthorized: DreamFactory session required'],
            ], 401);
        }

        $config = $this->getConfig();

        // Resolve accessible services server-side (role-filtered) so the daemon
        // never has to call GET /system/service — which a least-privilege role
        // legitimately can't reach.
        $availableServices = $this->resolveAvailableServices();

        $internalBase = config('mcp.daemon.internal_base_url');
        $baseUrl = !empty($internalBase)
            ? rtrim($internalBase, '/') . '/api/v2'
            : $request->getSchemeAndHttpHost() . '/api/v2';

        $jsonRpc = json_decode((string) $request->getContent(), true);
        if (!is_array($jsonRpc)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error: body is not valid JSON-RPC'],
            ], 400);
        }

        // The caller (df-ai-chat) speaks bare JSON-RPC with no session
        // handshake, so run the full initialize/initialized/call exchange
        // against the session-stateful daemon and return the final response.
        $result = (new McpDaemonClient())->rpcStateless(
            $this->name,
            is_array($config) ? $config : [],
            $baseUrl,
            $dfSessionToken,
            $availableServices,
            $jsonRpc,
        );

        // Return the decoded JSON-RPC object directly — DreamFactory serializes
        // the array as the JSON body. Returning a Laravel Response here would
        // get double-wrapped into a raw HTTP dump.
        return $result;
    }

    /**
     * Role-filtered DB + file service list the daemon auto-exposes as tools.
     *
     * ponytail: mirrors McpStreamController::getAvailableServices /
     * getUserAccessibleServiceIds. Extract to a shared helper if this internal
     * bridge outlives the demo rather than letting the two copies drift.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveAvailableServices(): array
    {
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $sm */
            $sm = app('df.service');
            $fields = ['id', 'name', 'label', 'type'];

            $services = array_merge(
                array_map(
                    fn ($s) => array_merge($s, ['category' => 'database']),
                    $sm->getServiceListByGroup(ServiceTypeGroups::DATABASE, $fields, true),
                ),
                array_map(
                    fn ($s) => array_merge($s, ['category' => 'file']),
                    $sm->getServiceListByGroup(ServiceTypeGroups::FILE, $fields, true),
                ),
            );

            if (!SessionUtilities::isSysAdmin()) {
                $ids = $this->accessibleServiceIds();
                if ($ids === null) {
                    // null => role grants all services; no filtering.
                } elseif (!empty($ids)) {
                    $services = array_values(array_filter(
                        $services,
                        fn ($s) => in_array((int) ($s['id'] ?? 0), $ids, true),
                    ));
                } else {
                    $services = [];
                }
            }

            return $services;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Service ids the current session's role can reach, or null when the role
     * grants access to every service.
     *
     * @return int[]|null
     */
    protected function accessibleServiceIds(): ?array
    {
        $roleServices = (array) SessionUtilities::get('role.services');
        $ids = [];
        foreach ($roleServices as $access) {
            $sid = $access['service_id'] ?? null;
            if ($sid === null || $sid === 0 || $sid === '') {
                return null; // "all services"
            }
            if (is_numeric($sid) && $sid > 0) {
                $ids[] = (int) $sid;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocPaths(): array
    {
        $studly = Str::studly($this->name ?? 'mcp');

        return [
            '/' => [
                'get' => [
                    'summary' => 'Retrieve MCP service configuration',
                    'description' => sprintf(
                        'Returns the MCP endpoint (%s) clients should use.',
                        $this->getMcpEndpoint()
                    ),
                    'operationId' => 'get' . $studly . 'McpService',
                    'responses' => [
                        '200' => [
                            '$ref' => '#/components/responses/McpServiceInfoResponse',
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Refresh MCP configuration',
                    'description' => 'Validates the stored configuration and returns the MCP endpoint metadata.',
                    'operationId' => 'sync' . $studly . 'McpService',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/McpConfigRequest',
                    ],
                    'responses' => [
                        '200' => [
                            '$ref' => '#/components/responses/McpConfigUpdateResponse',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocRequests(): array
    {
        return [
            'McpConfigRequest' => [
                'description' => 'Optional payload for updating/validating MCP configuration',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/McpConfigRequest'],
                    ],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocResponses(): array
    {
        return [
            'McpServiceInfoResponse' => [
                'description' => 'MCP service configuration details',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/McpServiceInfo'],
                    ],
                ],
            ],
            'McpConfigUpdateResponse' => [
                'description' => 'MCP configuration update confirmation',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/McpConfigUpdateResult'],
                    ],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocSchemas(): array
    {
        $endpoint = $this->getMcpEndpoint();

        return [
            'McpServiceInfo' => [
                'type' => 'object',
                'description' => 'MCP service configuration returned by GET request.',
                'properties' => [
                    'service_id' => [
                        'type' => 'integer',
                        'format' => 'int32',
                        'description' => 'Internal DreamFactory service identifier.',
                    ],
                    'mcp_endpoint' => [
                        'type' => 'string',
                        'description' => 'Fully-qualified MCP endpoint URL clients should call.',
                        'example' => $endpoint,
                    ],
                ],
                'required' => ['service_id', 'mcp_endpoint'],
            ],
            'McpConfigUpdateResult' => [
                'type' => 'object',
                'description' => 'Result of MCP configuration update via POST request.',
                'properties' => [
                    'ok' => [
                        'type' => 'boolean',
                        'description' => 'Indicates whether the MCP configuration update succeeded.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Human readable summary of the operation result.',
                    ],
                    'server' => [
                        '$ref' => '#/components/schemas/McpServerInfo',
                    ],
                ],
                'required' => ['ok', 'message', 'server'],
            ],
            'McpServerInfo' => [
                'type' => 'object',
                'description' => 'MCP server endpoint information.',
                'properties' => [
                    'mcp_endpoint' => [
                        'type' => 'string',
                        'description' => 'MCP endpoint URL for this service.',
                        'example' => $endpoint,
                    ],
                ],
                'required' => ['mcp_endpoint'],
            ],
            'McpConfigRequest' => [
                'type' => 'object',
                'description' => 'Optional payload for validating MCP configuration.',
                'properties' => [],
            ],
        ];
    }
}
