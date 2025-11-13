<?php


namespace DreamFactory\Core\McpServer\Services;

use DreamFactory\Core\McpServer\DreamFactoryService;
use Illuminate\Support\Facades\Cache;
use Mcp\Server;
use Mcp\Server\ServerBuilder;
use ReflectionClass;
use RuntimeException;

final class McpServerFactory
{
    private McpRegistry $registry;
    private int $ttl;

    public function __construct(McpRegistry $registry)
    {
        $this->registry = $registry;
        $this->ttl = (int) config('mcp.server_ttl_seconds', 1800);
    }

    public function forApi(string $apiName): Server
    {
        $config = $this->registry->get($apiName);
        
        if (!$config) {
            throw new RuntimeException("MCP server '{$apiName}' not found");
        }

        $cacheKey = "mcp_server_{$apiName}";
        
        return Cache::remember($cacheKey, $this->ttl, function () use ($apiName, $config) {
            return $this->buildServer($apiName, $config);
        });
    }

    private function buildServer(string $apiName, array $config): Server
    {
        // Extract API key from config or environment
        $apiKey = $config['api_key'] ?? '';
        
        // Construct baseUrl from apiName: /api/v2/{apiName}
        // Use app.url from config or construct from request
        $appUrl = rtrim(config('app.url', ''), '/');
        $baseUrl = $appUrl . '/api/v2/' . $apiName;
        
        // Create DreamFactoryService with the baseUrl for this API instance
        $dreamFactoryService = new DreamFactoryService($baseUrl, $apiKey);
        
        $builder = Server::builder();
        $toolsConfig = config('mcp.tools', []);
        
        foreach ($toolsConfig as $toolClass => $methods) {
            // Tool class is already fully qualified in config
            $fullClassName = $toolClass;
            
            if (!class_exists($fullClassName)) {
                continue;
            }
            
            $instance = $this->instantiateTool($fullClassName, $dreamFactoryService);
            
            foreach ($methods as $method => $toolName) {
                if (method_exists($instance, $method)) {
                    $builder->addTool([$instance, $method], $toolName);
                }
            }
        }
        
        $builder->setServerInfo("Laravel MCP ({$apiName})", '1.0.0');
        
        return $builder->build();
    }

    private function instantiateTool(string $className, DreamFactoryService $dreamFactoryService): object
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

    public function clearCache(string $apiName): void
    {
        Cache::forget("mcp_server_{$apiName}");
    }

    public function clearAllCache(): void
    {
        $all = $this->registry->all();
        foreach (array_keys($all) as $apiName) {
            $this->clearCache($apiName);
        }
    }
}

