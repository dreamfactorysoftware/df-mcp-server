<?php

namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use Illuminate\Support\Str;

class Mcp extends BaseRestService
{
    protected function getApiName(): ?string
    {
        return $this->getConfig('api_name');
    }

    protected function getMcpEndpoint(): string
    {
        return url('/mcp/' . $this->name);
    }

    protected function getValidatedApiName(): string
    {
        $apiName = $this->getApiName();

        if (!$apiName) {
            throw new BadRequestException('API name must be configured for this service.');
        }

        return $apiName;
    }

    protected function handleGET()
    {
        if ($this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST)) {
            return ResourcesWrapper::wrapResources($this->getAccessList());
        }

        return [
            'service_id' => $this->id,
            'api_name' => $this->getValidatedApiName(),
            'mcp_endpoint' => $this->getMcpEndpoint(),
        ];
    }

    protected function handlePOST()
    {
        return [
            'ok' => true,
            'message' => 'MCP server configuration updated successfully',
            'server' => [
                'api_name' => $this->getValidatedApiName(),
                'mcp_endpoint' => $this->getMcpEndpoint(),
            ],
        ];
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
                        'Returns the configured API name and the MCP endpoint (%s) clients should use.',
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
                    'api_name' => [
                        'type' => 'string',
                        'description' => 'Configured DreamFactory database service name.',
                    ],
                    'mcp_endpoint' => [
                        'type' => 'string',
                        'description' => 'Fully-qualified MCP endpoint URL clients should call.',
                        'example' => $endpoint,
                    ],
                ],
                'required' => ['service_id', 'api_name', 'mcp_endpoint'],
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
                    'api_name' => [
                        'type' => 'string',
                        'description' => 'The API name for this service.',
                    ],
                    'mcp_endpoint' => [
                        'type' => 'string',
                        'description' => 'MCP endpoint URL for this service.',
                        'example' => $endpoint,
                    ],
                ],
                'required' => ['api_name', 'mcp_endpoint'],
            ],
            'McpConfigRequest' => [
                'type' => 'object',
                'description' => 'Optional payload for validating MCP configuration.',
                'properties' => [
                    'api_name' => [
                        'type' => 'string',
                        'description' => 'Optional override for the API name.',
                    ],
                ],
            ],
        ];
    }
}
