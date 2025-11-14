<?php



namespace DreamFactory\Core\McpServer\Tools;

use DreamFactory\Core\McpServer\Services\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class CallStoredProcedureTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'call_stored_procedure', description: 'Call a stored procedure')]
    public function __invoke(string $procedureName, ?array $parameters = null, ?string $wrapper = null, ?string $returns = null): array
    {
        $data = $this->service->callStoredProcedure($procedureName, $parameters, $wrapper, $returns);
        return [
            'label' => "Stored procedure {$procedureName} called successfully:",
            'data' => $data,
        ];
    }
}

