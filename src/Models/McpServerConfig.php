<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class McpServerConfig extends BaseServiceConfigModel
{
    use SoftDeletes;

    protected $table = 'mcp_server_config';

    protected $fillable = [
        'service_id',
        'api_name',
        'api_key',
        'role',
    ];

    protected $casts = [
        'service_id' => 'integer'
    ];

    /**
     * @param array $schema
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'api_name':
                $schema['label'] = 'API Name';
                $schema['description'] = 'Your Dreamfactory API name.';
                break;
            case 'api_key':
                $schema['label'] = 'API Key';
                $schema['description'] = 'Dreamfactory API Key.';
                break;
            case 'role':
                $schema['label'] = 'Role';
                $schema['description'] = 'Dreamfactory Role for selected API Key.';
                break;
        }
    }
}

