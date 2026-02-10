<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class McpServerConfig extends BaseServiceConfigModel
{
    use SoftDeletes;

    protected $table = 'mcp_server_config';

    protected $fillable = [
        'service_id',
        'app_id',
        'oauth_client_id',
        'oauth_client_secret',
        'custom_login_url',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'app_id' => 'integer',
    ];

    /**
     * Fields to exclude from config schema (UI) but include in getConfig()
     */
    protected static $schemaHiddenFields = [
        'app_id',
    ];


    /**
     * Override to exclude schemaHiddenFields from UI
     */
    public static function getConfigSchema()
    {
        $schema = parent::getConfigSchema();

        if ($schema) {
            $schema = array_filter($schema, function ($field) {
                return !in_array($field['name'] ?? '', static::$schemaHiddenFields);
            });
            $schema = array_values($schema); // Re-index array
        }

        return $schema;
    }

    /**
     * @param array $schema
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'oauth_client_id':
                $schema['label'] = 'OAuth Client ID';
                $schema['description'] = 'OAuth Client ID for authentication.';
                $schema['default'] = self::generateOAuthClientId();
                break;
            case 'oauth_client_secret':
                $schema['label'] = 'OAuth Client Secret';
                $schema['description'] = 'OAuth Client Secret for authentication.';
                $schema['default'] = self::generateOAuthClientSecret();
                break;
            case 'custom_login_url':
                $schema['label'] = 'Custom Login URL';
                $schema['description'] = 'Optional custom login page URL. If set, users will be redirected here instead of the default DreamFactory login. Must use HTTPS.';
                $schema['type'] = 'text';
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
     * Validate custom login URL
     *
     * @param string|null $url
     * @return bool
     */
    public static function isValidCustomLoginUrl(?string $url): bool
    {
        if (empty($url)) {
            return true; // Empty is valid (optional field)
        }

        // Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsedUrl = parse_url($url);
        $scheme = $parsedUrl['scheme'] ?? '';
        $host = $parsedUrl['host'] ?? '';

        // Allow localhost with HTTP for development
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            return in_array($scheme, ['http', 'https']);
        }

        // Require HTTPS for all other hosts
        return $scheme === 'https';
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
            // Auto-set admin app if not provided
            if (empty($model->app_id)) {
                $model->app_id = self::getAdminAppId();
            }
        });
    }

    /**
     * Get the admin app ID
     */
    public static function getAdminAppId(): ?int
    {
        $adminApp = \DreamFactory\Core\Models\App::where('name', 'admin')->first();
        return $adminApp?->id;
    }
}

