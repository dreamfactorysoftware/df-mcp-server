<?php



namespace DreamFactory\Core\McpServer\Tools;

use DreamFactory\Core\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class CallStoredFunctionTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'call_stored_function', description: 'Call a stored function')]
    public function __invoke(string $functionName, ?array $parameters = null, ?string $returns = null): array
    {
        $data = $this->service->callStoredFunction($functionName, $parameters, $returns);
        return [
            'label' => "Stored function {$functionName} called successfully:",
            'data' => $data,
        ];
    }
}

