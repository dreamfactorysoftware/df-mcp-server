<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Models\BaseServiceConfigModel;
use ServiceManager;
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
                $services = ServiceManager::getServiceListByGroup(ServiceTypeGroups::DATABASE, ['name'], true);

                $apinSvcList = [
                    [
                        'label' => '',
                        'name'  => null
                    ]
                ];
                foreach ($services as $service) {
                    $apinSvcList[] = ['label' => array_get($service, 'name'), 'name' => array_get($service, 'name')];
                }

                $schema['type'] = 'picklist';
                $schema['values'] = $apinSvcList;
                $schema['label'] = 'API Name';
                $schema['description'] = 'Your Dreamfactory API name.';
                break;
            case 'api_key':
                $schema['label'] = 'API Key';
                $schema['description'] = 'Dreamfactory API Key.';
                break;
        }
    }
}

