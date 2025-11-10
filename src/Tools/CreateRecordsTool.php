<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class CreateRecordsTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'create_records', description: 'Create one or more records in a table')]
    public function __invoke(
        string $tableName,
        array $records,
        ?array $fields = null,
        ?string $related = null,
        ?bool $continue = null,
        ?bool $rollback = null
    ): array {
        $data = $this->service->createRecords($tableName, $records, $fields, $related, $continue, $rollback);
        return [
            'label' => "Records created in table {$tableName}:",
            'data' => $data,
        ];
    }
}
