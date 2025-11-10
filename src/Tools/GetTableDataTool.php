<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetTableDataTool
{
    private DreamFactoryService $service;

    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }

    /**
     * Retrieve data from a table with advanced filtering, pagination, and sorting.
     */
    #[McpTool(name: 'get_table_data', description: 'Retrieve data of a table with filtering, pagination, and sorting')]
    public function __invoke(
        string $tableName,
        ?array $fields = null,
        ?string $filter = null,
        ?int $offset = null,
        ?int $limit = null,
        ?string $order = null,
        ?string $group = null,
        ?bool $continue = null,
        ?string $related = null,
        ?bool $countOnly = null,
        ?bool $includeCount = null,
        ?bool $includeSchema = null,
        ?array $ids = null
    ): array {
        $data = $this->service->getTableData($tableName, $fields, $filter, $offset, $limit, $order, $group, $continue, $related, $countOnly, $includeCount, $includeSchema, $ids);
        return [
            'label' => "Data for table {$tableName}:",
            'data' => $data,
        ];
    }
}
