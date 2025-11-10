<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetTableRelationshipsTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'get_table_relationships', description: 'Retrieve relationships definition for the given table')]
    public function __invoke(string $tableName, ?bool $refresh = null): array
    {
        $data = $this->service->getTableRelationships($tableName, $refresh);
        return [
            'label' => "Relationships for table {$tableName}:",
            'data' => $data,
        ];
    }
}
