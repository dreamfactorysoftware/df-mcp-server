<?php



namespace DreamFactory\Core\McpServer\Tools;

use DreamFactory\Core\McpServer\Services\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetStoredFunctionsTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'get_stored_functions', description: 'Get stored functions available in the database')]
    public function __invoke(): array
    {
        $data = $this->service->getStoredFunctions();
        return [
            'label' => 'Stored functions available in the database:',
            'data' => $data,
        ];
    }
}

