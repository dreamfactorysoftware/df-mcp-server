<?php

namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\McpServer\Services\DreamFactoryService;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use Mcp\Server;
use ReflectionClass;

class Mcp extends BaseRestService
{
    protected $mcpServer;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);

        // Set MCP server if configuration is available
        // Similar to how BaseService sets transport in constructor
        $config = $this->getConfig();
        if (!empty($config['api_name'])) {
            $this->setMcpServer($config);
        }
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

    /**
     * Get the role from service config
     */
    protected function getRole(): ?string
    {
        return $this->getConfig('role');
    }

    /**
     * Handle GET request - Get MCP server information or handle MCP protocol requests
     */
    protected function handleGET()
    {
        // Check if this is an MCP protocol request (has jsonrpc in payload)
        $payload = $this->getPayloadData();
        if ($this->isMcpRequest($payload)) {
            return $this->handleMcpRequest();
        }

        // Standard GET - return service info
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();
        $role = $this->getRole();

        if (!$apiName || !$apiKey) {
            throw new BadRequestException('API name and API key must be configured for this service');
        }

        return [
            'service_id' => $this->serviceId,
            'api_name' => $apiName,
            'api_key' => '***', // Mask API key for security
            'role' => $role,
            'mcp_endpoint' => url('/api/v2/' . $this->serviceName),
        ];
    }

    /**
     * Handle POST request - Sync MCP server or handle MCP protocol requests
     */
    protected function handlePOST()
    {
        // Check if this is an MCP protocol request (has jsonrpc in payload)
        $payload = $this->getPayloadData();
        if ($this->isMcpRequest($payload)) {
            return $this->handleMcpRequest();
        }

        // Standard POST - sync configuration to registry
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();
        $role = $this->getRole();

        if (!$apiName || !$apiKey) {
            throw new BadRequestException('API name and API key must be configured for this service');
        }

        // Rebuild MCP server with updated config
        $config = $this->getConfig();
        $this->setMcpServer($config);

        return [
            'ok' => true,
            'message' => 'MCP server configuration updated successfully',
            'server' => [
                'api_name' => $apiName,
                'role' => $this->getRole(),
                'mcp_endpoint' => url('/api/v2/' . $this->serviceName),
            ],
        ];
    }

    /**
     * Check if request is an MCP protocol request
     */
    protected function isMcpRequest(?array $payload): bool
    {
        return $payload !== null
            && isset($payload['jsonrpc'])
            && $payload['jsonrpc'] === '2.0';
    }

    /**
     * Handle MCP protocol request (initialize, tools/list, tools/call, etc.)
     */
    protected function handleMcpRequest()
    {
        try {
            $apiName = $this->getApiName();

            if (!$apiName) {
                throw new BadRequestException('API name not configured for this service');
            }

            // Get MCP Server instance (using getMcpServer method)
            $mcpServer = $this->getMcpServer();

            // Convert to PSR-7 request
            $psrRequest = $this->createPsrRequest();

            // Create transport
            $transport = $this->createMcpTransport($psrRequest, $apiName);

            // Run MCP server
            $psrResponse = $mcpServer->run($transport);

            // Convert PSR-7 response to array
            return $this->psrResponseToArray($psrResponse);
        } catch (BadRequestException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new InternalServerErrorException('Failed to handle MCP request: ' . $e->getMessage());
        }
    }

    /**
     * Create PSR-7 request from current request
     */
    protected function createPsrRequest()
    {
        try {
            $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
            $request = $this->request ?? request();

            $psrHttpFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory(
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                $psr17Factory
            );

            return $psrHttpFactory->createRequest($request);
        } catch (\Exception $e) {
            throw new InternalServerErrorException('Failed to create PSR-7 request: ' . $e->getMessage());
        }
    }

    /**
     * Create MCP transport
     */
    protected function createMcpTransport($psrRequest, string $apiName)
    {
        $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        // Add mcp-session-id header
        $psrRequest = $psrRequest->withHeader('mcp-session-id', $apiName);

        return new \Mcp\Server\Transport\StreamableHttpTransport(
            $psrRequest,
            $psr17Factory,
            $psr17Factory
        );
    }

    /**
     * Convert PSR-7 response to array
     */
    protected function psrResponseToArray($psrResponse): array
    {
        try {
            $body = (string) $psrResponse->getBody();
            $decoded = json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            return ['result' => $body];
        } catch (\Exception $e) {
            throw new InternalServerErrorException('Failed to process MCP response: ' . $e->getMessage());
        }
    }

    /**
     * Set MCP server based on configuration
     * Similar to setTransport() in Email service
     */
    protected function setMcpServer(array $config): void
    {
        $apiName = $config['api_name'] ?? null;
        $apiKey = $config['api_key'] ?? null;

        if (empty($apiName)) {
            throw new BadRequestException('API name must be configured for this service');
        }

        if (empty($apiKey)) {
            throw new BadRequestException('API key must be configured for this service');
        }

        // Build MCP Server instance directly
        $this->mcpServer = $this->buildMcpServer($apiName, $apiKey);
    }

    /**
     * Build MCP Server instance
     * Similar to getTransport() static method in Email service
     */
    protected function buildMcpServer(string $apiName, string $apiKey): Server
    {
        // Construct baseUrl from apiName: /api/v2/{apiName}
        $appUrl = rtrim(config('app.url', ''), '/');
        $baseUrl = $appUrl . '/api/v2/' . $apiName;

        // Create DreamFactoryService with the baseUrl for this API instance
        $dreamFactoryService = new DreamFactoryService($baseUrl, $apiKey);

        $builder = Server::builder();
        $toolsConfig = config('mcp.tools', []);

        foreach ($toolsConfig as $toolClass => $methods) {
            if (!class_exists($toolClass)) {
                continue;
            }

            $instance = $this->instantiateTool($toolClass, $dreamFactoryService);

            foreach ($methods as $method => $toolName) {
                if (method_exists($instance, $method)) {
                    $builder->addTool([$instance, $method], $toolName);
                }
            }
        }

        $builder->setServerInfo("DreamFactory MCP ({$apiName})", '1.0.0');

        return $builder->build();
    }

    /**
     * Instantiate tool with DreamFactoryService injection
     */
    protected function instantiateTool(string $className, DreamFactoryService $dreamFactoryService): object
    {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $parameters = $constructor->getParameters();

        if (count($parameters) >= 1) {
            $firstParam = $parameters[0];
            $firstParamType = $firstParam->getType();
            $firstParamTypeName = $firstParamType instanceof \ReflectionNamedType
                ? $firstParamType->getName()
                : null;

            if ($firstParamTypeName === DreamFactoryService::class ||
                ($firstParam->allowsNull() && $firstParamTypeName === null)) {
                try {
                    return new $className($dreamFactoryService);
                } catch (\Throwable $e) {
                    // Fall through to next attempt
                }
            }
        }

        return new $className();
    }

    /**
     * Get the MCP Server instance for this service
     * Similar to getTransport() in Email service
     */
    public function getMcpServer(): \Mcp\Server
    {
        // If server is already set, return it
        if ($this->mcpServer instanceof \Mcp\Server) {
            return $this->mcpServer;
        }

        // Otherwise, set it from current config
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();

        if (empty($apiName) || empty($apiKey)) {
            throw new BadRequestException('API name and API key must be configured for this service');
        }

        $config = $this->getConfig();
        $this->setMcpServer($config);

        return $this->mcpServer;
    }
}

