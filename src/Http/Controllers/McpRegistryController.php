<?php



namespace DreamFactory\Core\McpServer\Http\Controllers;

use DreamFactory\Core\McpServer\Services\McpRegistry;
use DreamFactory\Core\McpServer\Services\McpServerFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

final class McpRegistryController
{
    private McpRegistry $registry;
    private McpServerFactory $factory;

    public function __construct(McpRegistry $registry, McpServerFactory $factory)
    {
        $this->registry = $registry;
        $this->factory = $factory;
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'apiName' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/'],
            'apiKey' => ['required', 'string'],
            'role' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $apiName = $request->input('apiName');
        $apiKey = $request->input('apiKey');
        $role = $request->input('role');

        $config = $this->registry->upsert($apiName, $apiKey, $role);
        
        // Clear factory cache for this server to force rebuild with new config
        $this->factory->clearCache($apiName);

        return response()->json([
            'ok' => true,
            'server' => $config,
            'endpoint' => route('mcp.runtime', ['apiName' => $apiName]),
        ], 201);
    }

    public function index(): JsonResponse
    {
        $servers = $this->registry->all();
        
        $result = [];
        foreach ($servers as $apiName => $config) {
            $result[] = [
                'api_name' => $apiName,
                ...$config,
                'endpoint' => route('mcp.runtime', ['apiName' => $apiName]),
            ];
        }
        
        return response()->json($result);
    }

    public function destroy(string $apiName): Response|JsonResponse
    {
        $deleted = $this->registry->delete($apiName);
        
        if (!$deleted) {
            return response()->json([
                'error' => 'Server not found',
            ], 404);
        }
        
        // Clear factory cache for deleted server
        $this->factory->clearCache($apiName);
        
        return response()->noContent();
    }
}
