<?php

return [
    'tools_namespace' => 'DreamFactory\Core\McpServer\Tools',

    'middleware' => [],
    
    'route_prefix' => null,
    
    'tools' => [
        'DreamFactory\Core\McpServer\Tools\GetTablesTool' => [
            '__invoke' => 'get_tables',
        ],
        'DreamFactory\Core\McpServer\Tools\GetTableDataTool' => [
            '__invoke' => 'get_table_data',
        ],
        'DreamFactory\Core\McpServer\Tools\GetTableFieldsTool' => [
            '__invoke' => 'get_table_fields',
        ],
        'DreamFactory\Core\McpServer\Tools\GetTableRelationshipsTool' => [
            '__invoke' => 'get_table_relationships',
        ],
        'DreamFactory\Core\McpServer\Tools\CreateRecordsTool' => [
            '__invoke' => 'create_records',
        ],
        'DreamFactory\Core\McpServer\Tools\UpdateRecordsTool' => [
            '__invoke' => 'update_records',
        ],
        'DreamFactory\Core\McpServer\Tools\DeleteRecordsTool' => [
            '__invoke' => 'delete_records',
        ],
        'DreamFactory\Core\McpServer\Tools\GetDatabaseResourcesTool' => [
            '__invoke' => 'get_database_resources',
        ],
        'DreamFactory\Core\McpServer\Tools\GetStoredProceduresTool' => [
            '__invoke' => 'get_stored_procedures',
        ],
        'DreamFactory\Core\McpServer\Tools\CallStoredProcedureTool' => [
            '__invoke' => 'call_stored_procedure',
        ],
        'DreamFactory\Core\McpServer\Tools\GetStoredFunctionsTool' => [
            '__invoke' => 'get_stored_functions',
        ],
        'DreamFactory\Core\McpServer\Tools\CallStoredFunctionTool' => [
            '__invoke' => 'call_stored_function',
        ],
        'DreamFactory\Core\McpServer\Tools\SearchTool' => [
            '__invoke' => 'search',
        ],
        'DreamFactory\Core\McpServer\Tools\FetchTool' => [
            '__invoke' => 'fetch',
        ],
    ],
    
    'server_ttl_seconds' => 1800,
];

