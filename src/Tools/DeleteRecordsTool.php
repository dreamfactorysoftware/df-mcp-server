<?php



namespace DreamFactory\Core\McpServer\Tools;

use DreamFactory\Core\McpServer\Services\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class DeleteRecordsTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'delete_records', description: 'Delete one or more records from a table')]
    public function __invoke(
        string $tableName,
        ?array $ids = null,
        ?string $filter = null,
        ?bool $force = null,
        ?array $fields = null,
        ?string $related = null,
        ?bool $continue = null,
        ?bool $rollback = null
    ): array {
        $data = $this->service->deleteRecords($tableName, $ids, $filter, $force, $fields, $related, $continue, $rollback);
        return [
            'label' => "Records deleted from table {$tableName}:",
            'data' => $data,
        ];
    }
}

