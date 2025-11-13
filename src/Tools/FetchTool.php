<?php



namespace DreamFactory\Core\McpServer\Tools;

use Mcp\Capability\Attribute\McpTool;

final class FetchTool
{
    #[McpTool(name: 'fetch', description: 'Stub — returns an empty document, not connected to actual records.')]
    public function __invoke(string $id): array
    {
        return [
            'id' => $id,
            'title' => '',
            'text' => '',
            'url' => '',
            'metadata' => null
        ];
    }
}

