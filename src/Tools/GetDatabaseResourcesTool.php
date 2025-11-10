<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use DreamFactory\McpServer\DreamFactoryService;
use Mcp\Capability\Attribute\McpTool;

final class GetDatabaseResourcesTool
{
    private DreamFactoryService $service;
    public function __construct(?DreamFactoryService $service = null)
    {
        $this->service = $service ?? new DreamFactoryService();
    }
    #[McpTool(name: 'get_database_resources', description: 'Get all resources available in the database service')]
    public function __invoke(
        ?bool $asList = null,
        ?bool $asAccessList = null,
        ?bool $includeAccess = null,
        ?array $fields = null,
        ?bool $refresh = null
    ): array {
        $data = $this->service->getDatabaseResources($asList, $asAccessList, $includeAccess, $fields, $refresh);
        return [
            'label' => 'Database resources:',
            'data' => $data,
        ];
    }
}
