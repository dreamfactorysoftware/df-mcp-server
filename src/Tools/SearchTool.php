<?php

declare(strict_types=1);

namespace DreamFactory\McpServer\Tools;

use Mcp\Capability\Attribute\McpTool;

final class SearchTool
{
    #[McpTool(name: 'search', description: 'Stub — this tool does not provide actual search functionality for the database.')]
    public function __invoke(string $query): array
    {
        return [ 'results' => [] ];
    }
}
