<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetTablesTool
{
    private DreamFactoryService $service;

    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }

    /**
     * List all tables in the DreamFactory database.
     */
    #[McpTool(name: 'get_tables', description: 'Get tables available in the database')]
    public function __invoke(): array
    {
        $tables = $this->service->getTables();
        return [
            'label' => 'Tables available in the database:',
            'data' => $tables,
        ];
    }
}
