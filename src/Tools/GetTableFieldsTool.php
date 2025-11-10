<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetTableFieldsTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'get_table_fields', description: 'Retrieve table field definitions for the given table')]
    public function __invoke(string $tableName, ?bool $refresh = null): array
    {
        $data = $this->service->getTableFields($tableName, $refresh);
        return [
            'label' => "Fields for table {$tableName}:",
            'data' => $data,
        ];
    }
}
