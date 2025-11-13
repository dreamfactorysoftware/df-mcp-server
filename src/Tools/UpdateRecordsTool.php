<?php



namespace DreamFactory\Core\McpServer\Tools;

use DreamFactory\Core\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class UpdateRecordsTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'update_records', description: 'Update one or more records in a table')]
    public function __invoke(
        string $tableName,
        array $records,
        ?array $fields = null,
        ?string $related = null,
        ?array $ids = null,
        ?string $filter = null,
        ?bool $continue = null,
        ?bool $rollback = null
    ): array {
        $data = $this->service->updateRecords($tableName, $records, $fields, $related, $ids, $filter, $continue, $rollback);
        return [
            'label' => "Records updated in table {$tableName}:",
            'data' => $data,
        ];
    }
}

