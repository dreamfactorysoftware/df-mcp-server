<?php

namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Str;

class Mcp extends BaseRestService
{
    public function __construct(array $settings = [])
    {
        parent::__construct($settings);
    }

    /**
     * Get the API name from service config
     */
    protected function getApiName(): ?string
    {
        return $this->getConfig('api_name');
    }

    /**
     * Get the API key from service config
     */
    protected function getApiKey(): ?string
    {
        return $this->getConfig('api_key');
    }

    protected function getServiceName(): string
    {
        return $this->name;
    }

    protected function handleGET()
    {
        if ($this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST)) {
            return ResourcesWrapper::wrapResources($this->getAccessList());
        }

        // Standard GET - return service info
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();

        if (!$apiName || !$apiKey) {
            throw new BadRequestException('API name and API key must be configured for this service. Please configure the service with api_name and api_key settings.');
        }

        $serviceId = $this->id;

        return [
            'service_id' => $serviceId,
            'api_name' => $apiName,
            'api_key' => $apiKey,
            'mcp_endpoint' => url('/mcp/' . $this->getServiceName()),
        ];
    }

    protected function handlePOST()
    {
        // Standard POST - sync configuration to registry
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();

        if (!$apiName || !$apiKey) {
            throw new BadRequestException('API name and API key must be configured for this service. Please configure the service with api_name and api_key settings.');
        }

        return [
            'ok' => true,
            'message' => 'MCP server configuration updated successfully',
            'server' => [
                'api_name' => $apiName,
                'mcp_endpoint' => url('/mcp/' . $this->getServiceName()),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocPaths(): array
    {
        $studly = Str::studly($this->name ?? 'mcp');
        $daemonEndpoint = url('/mcp/' . $this->getServiceName());

        return [
            '/' => [
                'get' => [
                    'summary' => 'Retrieve MCP service configuration',
                    'description' => sprintf(
                        'Returns the configured API name/key and the daemon endpoint (%s) clients should use for MCP traffic.',
                        $daemonEndpoint
                    ),
                    'operationId' => 'get' . $studly . 'McpService',
                    'responses' => [
                        '200' => [
                            '$ref' => '#/components/responses/McpServiceInfoResponse',
                        ],
                    ],
                ],
                'post' => [
                    'summary' => 'Refresh MCP configuration and registry entry',
                    'description' => 'Validates the stored configuration and returns the daemon endpoint metadata so automation systems can verify connectivity.',
                    'operationId' => 'sync' . $studly . 'McpService',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/McpConfigRequest',
                    ],
                    'responses' => [
                        '200' => [
                            '$ref' => '#/components/responses/McpServiceInfoResponse',
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
                'description' => 'MCP service information and daemon endpoint metadata',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/McpServiceInfo'],
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
        $daemonEndpoint = url('/mcp/' . $this->getServiceName());

        return [
            'McpServiceInfo' => [
                'type' => 'object',
                'properties' => [
                    'ok' => [
                        'type' => 'boolean',
                        'description' => 'Indicates whether the MCP configuration check succeeded.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Human readable summary of the MCP service status.',
                    ],
                    'service_id' => [
                        'type' => 'integer',
                        'format' => 'int32',
                        'description' => 'Internal DreamFactory service identifier.',
                    ],
                    'api_name' => [
                        'type' => 'string',
                        'description' => 'Configured DreamFactory service name used when registering with the daemon.',
                    ],
                    'api_key' => [
                        'type' => 'string',
                        'description' => 'API key that allows the daemon to authenticate against DreamFactory.',
                    ],
                    'server' => [
                        '$ref' => '#/components/schemas/McpServerInfo',
                    ],
                    'mcp_endpoint' => [
                        'type' => 'string',
                        'description' => 'Fully-qualified daemon URL clients should call.',
                        'example' => $daemonEndpoint,
                    ],
                ],
            ],
            'McpServerInfo' => [
                'type' => 'object',
                'properties' => [
                    'api_name' => [
                        'type' => 'string',
                        'description' => 'The API name registered with the daemon.',
                    ],
                    'mcp_endpoint' => [
                        'type' => 'string',
                        'description' => 'Daemon endpoint for this service.',
                        'example' => $daemonEndpoint,
                    ],
                ],
            ],
            'McpConfigRequest' => [
                'type' => 'object',
                'properties' => [
                    'api_name' => [
                        'type' => 'string',
                        'description' => 'Optional override for the API name to register with the daemon.',
                    ],
                    'api_key' => [
                        'type' => 'string',
                        'description' => 'Optional override for the API key to send to the daemon.',
                    ],
                ],
            ],
        ];
    }
}

