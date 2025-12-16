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
        'oauth_client_id',
        'oauth_client_secret',
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
            case 'oauth_client_id':
                $schema['label'] = 'OAuth Client ID (Optional)';
                $schema['description'] = 'Pre-shared OAuth Client ID. When set, only clients with this exact ID can connect. Leave empty to auto-generate on first save.';
                break;
            case 'oauth_client_secret':
                $schema['label'] = 'OAuth Client Secret (Optional)';
                $schema['description'] = 'Pre-shared OAuth Client Secret for authentication. Leave empty to auto-generate on first save.';
                break;
        }
    }

    /**
     * Generate a unique OAuth client ID
     */
    public static function generateOAuthClientId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate an OAuth client secret
     */
    public static function generateOAuthClientSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Boot method to auto-generate OAuth credentials on create
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Auto-generate OAuth credentials if not provided
            if (empty($model->oauth_client_id)) {
                $model->oauth_client_id = self::generateOAuthClientId();
            }
            if (empty($model->oauth_client_secret)) {
                $model->oauth_client_secret = self::generateOAuthClientSecret();
            }
        });
    }
}

