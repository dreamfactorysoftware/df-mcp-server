<?php

namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Core\Utility\ServiceResponse;
use Illuminate\Support\Facades\Log;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Symfony\Component\Uid\Uuid;

class Mcp extends BaseRestService
{
    protected $mcpServer;

    public function __construct(array $settings = [])
    {
        parent::__construct($settings);

        // Set MCP server if configuration is available
        $config = $this->getConfig();
        if (!empty($config['api_name'])) {
            try {
                $this->setMcpServer($config);
            } catch (\Exception $e) {
                \Log::error('Failed to initialize MCP server', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            \Log::warning('MCP service created without api_name configuration', [
                'config_keys' => array_keys($config ?? [])
            ]);
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
     * Get service name from request or config
     * serviceName is set by BaseRestService during request handling,
     * but may not be available in constructor
     */
    protected function getServiceName(): string
    {
        // First try to get from BaseRestService property (set during request handling)
        if (isset($this->serviceName) && !empty($this->serviceName) && $this->serviceName !== 'unknown') {
            return $this->serviceName;
        }

        // Try to get from request
        $request = $this->request ?? request();
        if ($request) {
            // ServiceRequest doesn't have segment(), so extract from path or URI
            try {
                // Try to get path from request
                $path = method_exists($request, 'path') ? $request->path() : null;
                if (!$path && method_exists($request, 'getPathInfo')) {
                    $path = $request->getPathInfo();
                }
                if (!$path && method_exists($request, 'getUri')) {
                    $uri = $request->getUri();
                    $path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : $uri->getPath();
                }
                
                if ($path) {
                    $parts = explode('/', trim($path, '/'));
                    // Look for service name after 'v2' in path like 'api/v2/{serviceName}'
                    $v2Index = array_search('v2', $parts);
                    if ($v2Index !== false && isset($parts[$v2Index + 1])) {
                        $serviceName = $parts[$v2Index + 1];
                        if ($serviceName && $serviceName !== 'api' && $serviceName !== 'v2') {
                            return $serviceName;
                        }
                    }
                    // Alternative: if path starts with api/v2/, service name is at index 2
                    if (isset($parts[0]) && $parts[0] === 'api' && isset($parts[1]) && $parts[1] === 'v2' && isset($parts[2])) {
                        return $parts[2];
                    }
                }
            } catch (\Exception $e) {
                // Ignore extraction failures and fall back to other strategies
            }
        }

        // Fallback to api_name from config
        $apiName = $this->getApiName();
        if ($apiName) {
            return $apiName;
        }

        return 'unknown';
    }

    /**
     * Handle GET request - Get MCP server information or handle MCP protocol requests
     */
    protected function handleGET()
    {

        $accept = null;

        if (($this->request ?? null) instanceof \Symfony\Component\HttpFoundation\Request) {
            $accept = $this->request->getHeader('Accept');
        } else {
            $accept = request()->header('Accept');
        }

        if (is_string($accept) && str_contains($accept, 'text/event-stream')) {
            $sr = new ServiceResponse();
            $sr->setStatusCode(200);
            $sr->setContent('');
            $sr->setContentType('text/event-stream');
            $sr->setHeaders(['Content-Type' => 'text/event-stream']);

            return $sr;
        }

        if ($this->request->getParameterAsBool(ApiOptions::AS_ACCESS_LIST)) {
            return ResourcesWrapper::wrapResources($this->getAccessList());
        }
        // Check if this is an MCP protocol request (has jsonrpc in payload)
        $payload = $this->getPayloadData();

        if ($this->isMcpRequest($payload)) {
            return $this->handleMcpRequest();
        }

        // If payload exists, try to handle as MCP request anyway
        if ($payload !== null && !empty($payload)) {
            try {
                return $this->handleMcpRequest();
            } catch (\Exception $e) {
            }
        }

        // Standard GET - return service info
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();
        $role = $this->getRole();

        if (!$apiName || !$apiKey) {
            throw new BadRequestException('API name and API key must be configured for this service. Please configure the service with api_name and api_key settings.');
        }

        $serviceId = $this->id;

        return [
            'service_id' => $serviceId,
            'api_name' => $apiName,
            'api_key' => $apiKey,
            'role' => $role,
            'mcp_endpoint' => url('/api/v2/' . $this->getServiceName()),
        ];
    }

    /**
     * Handle POST request - Sync MCP server or handle MCP protocol requests
     */
    protected function handlePOST()
    {
        try {
            // Check if this is an MCP protocol request (has jsonrpc in payload)
            $payload = $this->getPayloadData();

            \Log::info('MCP POST request payload', [
                'payload' => $payload,
                'payload_type' => gettype($payload),
                'is_mcp_request' => $payload !== null && is_array($payload) ? $this->isMcpRequest($payload) : false,
                'raw_body' => substr($this->request->getContent(), 0, 500), // First 500 chars
            ]);
        } catch (\Exception $e) {
            \Log::error('Mcp service: Failed to get payload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // For POST requests, prioritize MCP protocol handling
        // MCP client sends POST requests with JSON-RPC protocol
        if ($payload !== null && is_array($payload)) {
            // Check if it's a JSON-RPC request (MCP protocol)
            if ($this->isMcpRequest($payload)) {
                return $this->handleMcpRequest();
            }
            
            // If payload looks like JSON-RPC (has method or id), try MCP handling
            if (isset($payload['method']) || isset($payload['id']) || isset($payload['jsonrpc'])) {
                try {
                    \Log::info('Attempting MCP handling for JSON-RPC-like request');
                    return $this->handleMcpRequest();
                } catch (\Exception $e) {
                    \Log::warning('MCP handling failed', ['error' => $e->getMessage()]);
                    // If MCP handling fails, fall through to standard POST
                }
            }
        }

        // Standard POST - sync configuration to registry
        $apiName = $this->getApiName();
        $apiKey = $this->getApiKey();
        $role = $this->getRole();

        if (!$apiName || !$apiKey) {
            throw new BadRequestException('API name and API key must be configured for this service. Please configure the service with api_name and api_key settings.');
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
                'mcp_endpoint' => url('/api/v2/' . $this->getServiceName()),
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
            $payload = $this->getPayloadData();
            $apiName = $this->getApiName();

            if (!$apiName) {
                \Log::error('MCP request failed: API name not configured', [
                    'service_name' => $this->getServiceName(),
                    'config' => $this->config ?? null
                ]);
                throw new BadRequestException('API name not configured for this service. Please configure the service with api_name setting.');
            }

            // Get MCP Server instance (using getMcpServer method)
            $mcpServer = $this->getMcpServer();

            // Convert to PSR-7 request
            $psrRequest = $this->createPsrRequest();

            // Create transport
            $transport = $this->createMcpTransport($psrRequest, $apiName);

            // Run MCP server
            $psrResponse = $mcpServer->run($transport);

            // Check if SDK returned session ID in response headers
            $sessionId = $psrResponse->getHeaderLine('mcp-session-id');

            // Convert PSR-7 response to array
            $response = $this->psrResponseToArray($psrResponse);

            if (isset($response['result']['capabilities']) && is_array($response['result']['capabilities'])) {
                $caps = &$response['result']['capabilities'];

                foreach (['tools', 'prompts', 'resources', 'logging', 'completions'] as $key) {
                    if (array_key_exists($key, $caps) && $caps[$key] === []) {
                        $caps[$key] = (object)[];
                    }
                }

                if (!array_key_exists('tools', $caps)) {
                    $caps['tools'] = (object)[];
                }
            }
            
            // Log response for debugging
            $method = $payload['method'] ?? 'unknown';
            \Log::info('MCP response', [
                'method' => $method,
                'response_keys' => array_keys($response),
                'has_result' => isset($response['result']),
                'has_error' => isset($response['error']),
                'session_id' => $sessionId ?: 'none',
                'response_preview' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
            
            // If we have a session ID, add it to response headers
            $jsonResponse = response()->json($response);
            if (!empty($sessionId)) {
                $jsonResponse->header('mcp-session-id', $sessionId);
            }
            
            return $jsonResponse;
        } catch (BadRequestException $e) {
            \Log::error('MCP request BadRequestException', [
                'error' => $e->getMessage(),
                'api_name' => $this->getApiName() ?? 'unknown'
            ]);
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Failed to handle MCP request', [
                'error' => $e->getMessage(),
                'api_name' => $this->getApiName() ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
            throw new InternalServerErrorException('Failed to handle MCP request: ' . $e->getMessage());
        }
    }

    /**
     * Create PSR-7 request from current request
     */
    protected function createPsrRequest()
    {
        try {
            // Check if required classes are available
            if (!class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
                throw new InternalServerErrorException(
                    'PSR-7 factory not found. Please install required dependencies: ' .
                    'composer require nyholm/psr7 symfony/psr-http-message-bridge'
                );
            }

            if (!class_exists(\Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory::class)) {
                throw new InternalServerErrorException(
                    'PSR HTTP Message Bridge not found. Please install: ' .
                    'composer require symfony/psr-http-message-bridge'
                );
            }

            $psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();

            // Convert DreamFactory ServiceRequest to Symfony Request if needed
            $symfonyRequest = null;
            if (($this->request ?? null) instanceof \Symfony\Component\HttpFoundation\Request) {
                $symfonyRequest = $this->request;
            } else {
                $illuminateRequest = request();
                if ($illuminateRequest instanceof \Symfony\Component\HttpFoundation\Request) {
                    $symfonyRequest = $illuminateRequest;
                }
            }

            if (!$symfonyRequest instanceof \Symfony\Component\HttpFoundation\Request) {
                throw new InternalServerErrorException(
                    'Unable to access HTTP request instance required for MCP transport.'
                );
            }

            $psrHttpFactory = new \Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory(
                $psr17Factory,
                $psr17Factory,
                $psr17Factory,
                $psr17Factory
            );

            return $psrHttpFactory->createRequest($symfonyRequest);
        } catch (InternalServerErrorException $e) {
            throw $e;
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

        // Let SDK handle session creation automatically
        // If client provides mcp-session-id, it will be used
        // If not, SDK will create a new session on initialize
        // Only add service name header for tracking
        $psrRequest = $psrRequest->withHeader('mcp-service-name', $apiName);

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
        $appUrl = rtrim(config('app.url', ''), '/');
        $baseUrl = $appUrl . '/api/v2/' . $apiName;

        $dreamFactoryService = new DreamFactoryService($baseUrl, $apiKey);

        $builder = Server::builder();
        $toolsConfig = config('mcp.tools', []);

        foreach ($toolsConfig as $toolClass => $methods) {
            if (!class_exists($toolClass)) {
                \Log::warning('Tool class does not exist', ['class' => $toolClass]);
                continue;
            }

            foreach ($methods as $method => $toolName) {
                if (!method_exists($toolClass, $method)) {
                    continue;
                }

                try {
                    $builder->addTool([$toolClass, $method], $toolName);
                } catch (\Exception $e) {
                    \Log::error('Failed to register tool', [
                        'tool'   => $toolName,
                        'class'  => $toolClass,
                        'method' => $method,
                        'error'  => $e->getMessage(),
                        'trace'  => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        $builder->setServerInfo("DreamFactory MCP ({$apiName})", '1.0.0');

        $sessionPath = storage_path('app/mcp-sessions');
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0755, true);
        }
        $builder->setSession(new FileSessionStore($sessionPath));

        return $builder->build();
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

