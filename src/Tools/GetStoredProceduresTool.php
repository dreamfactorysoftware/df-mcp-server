<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetStoredProceduresTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'get_stored_procedures', description: 'Get stored procedures available in the database')]
    public function __invoke(): array
    {
        $data = $this->service->getStoredProcedures();
        return [
            'label' => 'Stored procedures available in the database:',
            'data' => $data,
        ];
    }
}
