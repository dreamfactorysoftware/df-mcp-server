<?php

namespace DreamFactory\Core\McpServer\Models;

use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\McpServer\Utility\AvailableServices;
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
        'redirect_uris',
        'custom_login_url',
        'auto_oauth_service',
        'allow_api_key_auth',
        'disabled_tools',
        'lazy_mode',
        'exposed_services',
        'scope_tools',
        'tool_style',
        'allow_writes',
    ];

    protected $casts = [
        'service_id' => 'integer',
        'app_id' => 'integer',
        'allow_api_key_auth' => 'boolean',
        'disabled_tools' => 'array',
        'exposed_services' => 'array',
        'scope_tools' => 'boolean',
        'allow_writes' => 'boolean',
        'redirect_uris' => 'array',
    ];

    /**
     * Fields to exclude from config schema (UI) but include in getConfig()
     */
    protected static $schemaHiddenFields = [
        'app_id',
        'disabled_tools',
        'custom_tools',
        // Tri-state (true / false / inherit MCP_SCOPE_TOOLS). A boolean
        // checkbox cannot represent "unset", so this stays API-only. Admins
        // pick backends via Exposed Services.
        'scope_tools',
    ];

    /**
     * Override to include custom tools from the mcp_custom_tools table.
     */
    public static function getConfig($id, $local_config = null, $protect = true)
    {
        $config = parent::getConfig($id, $local_config, $protect);

        try {
            $config['custom_tools'] = McpCustomTool::getAllForService($id);
        } catch (\Exception $e) {
            // Table may not exist yet if migration hasn't run
            $config['custom_tools'] = [];
        }

        // Read-only view of the redirect URIs clients registered for themselves
        // via dynamic client registration. These live on the OAuth client row,
        // not this config, so without surfacing them here an admin sees only
        // part of the allowlist actually enforced at /authorize.
        $config['registered_redirect_uris'] = self::getRegisteredRedirectUris(
            $config['oauth_client_id'] ?? null,
            self::normalizeRedirectUris($config['redirect_uris'] ?? null)
        );

        return $config;
    }

    /**
     * Redirect URIs registered against this service's OAuth client, minus any
     * the admin already manages through the redirect_uris config field.
     */
    protected static function getRegisteredRedirectUris(?string $clientId, array $configuredUris = []): array
    {
        if (empty($clientId)) {
            return [];
        }

        try {
            $client = McpOAuthClient::findByClientId($clientId);
        } catch (\Throwable $e) {
            // Table may not exist yet if migration hasn't run
            return [];
        }

        if (!$client) {
            return [];
        }

        // register() merges the configured URIs into the client row, so subtract
        // them here — otherwise every admin-managed URI is listed twice in the UI,
        // once as editable and once as read-only.
        return array_values(array_diff(
            self::normalizeRedirectUris($client->redirect_uris),
            $configuredUris
        ));
    }

    /**
     * Override to handle custom_tools sync on save.
     *
     * On initial service create, $id is null here (the service has not been
     * inserted yet). The sync runs from storeConfig() below, which fires
     * after the service row is created with a real id.
     */
    public static function setConfig($id, $config, $local_config = null)
    {
        $customTools = $config['custom_tools'] ?? null;
        unset($config['custom_tools']);

        // Read-only projection of the OAuth client row — never written back.
        unset($config['registered_redirect_uris']);

        parent::setConfig($id, $config, $local_config);
        static::warnIfEmptyExposed($id, $config);

        if ($id && is_array($customTools)) {
            self::syncCustomTools((int) $id, $customTools);
        }
    }

    /**
     * Override to sync custom_tools when a new service's config is stored
     * for the first time (post-insert, when the service id is known).
     */
    public static function storeConfig($id, $config)
    {
        $customTools = $config['custom_tools'] ?? null;
        unset($config['custom_tools']);

        // Read-only projection of the OAuth client row — never written back.
        unset($config['registered_redirect_uris']);

        parent::storeConfig($id, $config);
        static::warnIfEmptyExposed($id, $config);

        if ($id && is_array($customTools)) {
            self::syncCustomTools((int) $id, $customTools);
        }
    }

    /**
     * Empty Exposed Services means no auto-generated DB/file tools. Log it so
     * an admin who saved without picking backends can find the cause in logs.
     * Custom-tools-only MCP services are valid — this is not a validation error.
     *
     * Judges the RESULTING stored config, not the request payload: df-core
     * merges partial writes (firstOrNew + fill), so a save that simply omits
     * exposed_services keeps the stored names and must not warn. Only the
     * genuinely-empty outcome warns — stored list empty/null AND scoping
     * actually applies (an explicit scope_tools=false still serves the legacy
     * instance-wide catalog, so nothing is lost there).
     *
     * Overridable (static:: dispatch): SystemMcpServerConfig no-ops it, since
     * the system daemon has no DB/file tool catalog at all.
     */
    protected static function warnIfEmptyExposed($id, array $config): void
    {
        try {
            // Payload values back the pre-insert validation pass ($id null),
            // where nothing has been stored yet.
            $exposed = $config['exposed_services'] ?? null;
            $scopeTools = $config['scope_tools'] ?? null;

            if ($id && ($stored = static::whereServiceId($id)->first())) {
                // parent::setConfig/storeConfig already saved: the row is the
                // resulting state, whatever the payload carried or omitted.
                $exposed = $stored->exposed_services;
                $scopeTools = $stored->scope_tools;
            }

            if (AvailableServices::names($exposed) !== []) {
                return;
            }

            $scopeByDefault = filter_var(config('mcp.scope_tools', true), FILTER_VALIDATE_BOOLEAN);
            if (!AvailableServices::scopingApplies($exposed, $scopeTools, $scopeByDefault)) {
                return;
            }

            \Log::warning('MCP service has no Exposed Services selected; tools/list will not include database or file tools', [
                'service_id' => $id,
            ]);
        } catch (\Throwable $e) {
            // A log-time convenience must never break config saves (e.g.
            // table missing mid-migration).
        }
    }

    private static function syncCustomTools(int $serviceId, array $customTools): void
    {
        try {
            McpCustomTool::syncToolsForService($serviceId, $customTools);
        } catch (\Throwable $e) {
            \Log::error('Failed to sync MCP custom tools', [
                'service_id' => $serviceId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

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
                $schema['description'] = 'Auto-generated public identifier this MCP service registers itself with. External MCP clients (Claude Desktop, Cursor) discover it via the OAuth metadata endpoint — you normally don\'t edit this. Regenerate if it leaks.';
                $schema['default'] = self::generateOAuthClientId();
                break;
            case 'oauth_client_secret':
                $schema['label'] = 'OAuth Client Secret';
                $schema['description'] = 'Auto-generated secret paired with the Client ID. Treat like a password — never commit to source control. Regenerate to invalidate all currently-issued tokens.';
                $schema['default'] = self::generateOAuthClientSecret();
                break;
            case 'redirect_uris':
                $schema['label'] = 'Allowed Redirect URIs';
                $schema['description'] = 'OAuth callback URLs permitted for this service, one per line. Clients that support dynamic registration (Claude) add their own automatically and do not need an entry here. Add one for any client that does not — e.g. Mistral Vibe uses https://callback.mistral.ai/v1/integrations_auth/oauth2_callback. Leave empty to let the first client that connects register its own callback.';
                $schema['type'] = 'array';
                $schema['items'] = 'string';
                $schema['default'] = [];
                break;
            case 'custom_login_url':
                $schema['label'] = 'Custom Login URL';
                $schema['description'] = 'Optional. Send users to your own branded login page during the MCP OAuth flow instead of DreamFactory\'s default. The page must call DF\'s session-create endpoint and post back; HTTPS required (localhost is exempt for dev).';
                $schema['type'] = 'text';
                break;
            case 'tool_style':
                $schema['label'] = 'Database Tool Style';
                $schema['description'] = 'How database tools are exposed. Prefixed (default, unchanged): every verb is emitted once per database, so five databases produce five near-identical copies of all 16 tools. Merged: each verb is registered once and takes a "service" argument naming the database; endpoints exposing a single database omit the argument entirely. Merged cuts catalog size and token cost roughly in proportion to the number of databases, at the cost of breaking client configs that call the prefixed tool names.';
                $schema['type'] = 'picklist';
                $schema['default'] = 'prefixed';
                $schema['values'] = [
                    ['label' => 'Prefixed per service (default)', 'name' => 'prefixed'],
                    ['label' => 'Merged with a service argument', 'name' => 'merged'],
                ];
                break;
            case 'lazy_mode':
                $schema['label'] = 'Lazy Tool Loading';
                $schema['description'] = 'Hide the full tool catalog behind search_tools / describe_tool / call_tool / fetch_more so AI clients spend far fewer tokens per turn. Auto: only when the catalog is large (over ~8k tokens). On: always. Off: advertise every tool (legacy). Clients that defer schemas themselves (Codex, Grok, Hermes) always get the full catalog.';
                $schema['type'] = 'picklist';
                $schema['default'] = 'auto';
                $schema['values'] = [
                    ['label' => 'Auto (large catalogs only)', 'name' => 'auto'],
                    ['label' => 'On', 'name' => 'on'],
                    ['label' => 'Off', 'name' => 'off'],
                ];
                break;
            case 'auto_oauth_service':
                $schema['label'] = 'Auto OAuth Service';
                $schema['description'] = 'Optional. Name of a DF OAuth service (e.g. "google", "okta"). When set, MCP clients skip the login page entirely and go straight to that provider. Takes precedence over Custom Login URL. Use this for SSO-only environments.';
                $schema['type'] = 'text';
                break;
            case 'exposed_services':
                $schema['type'] = 'multi_picklist';
                $schema['label'] = 'Exposed Services';
                $schema['legend'] = 'Database and file services this MCP endpoint exposes as tools';
                $schema['description'] = 'Pick at least one database or file service or this MCP endpoint will not expose table/file tools (custom tools, search, and fetch still register). Empty always means none — it does not fall back to every service on the instance.';
                $schema['values'] = self::backendServiceChoices();
                break;
            case 'allow_writes':
                $schema['label'] = 'Allow writes';
                $schema['description'] = 'On (default): tools can create, update and delete records and files and call stored procedures/functions, subject to the role. Off: this MCP server is read-only — the write tools are not offered to clients at all, regardless of role or per-tool settings. Clients must reconnect to pick up a change.';
                $schema['type'] = 'boolean';
                $schema['default'] = true;
                break;
            case 'allow_api_key_auth':
                $schema['label'] = 'Allow API Key Authentication';
                $schema['description'] = 'Enable API key authentication as an alternative to OAuth. When enabled, clients can authenticate using the X-DreamFactory-API-Key header; the key\'s app must be active and have a role assigned, and that role scopes access. Optionally include X-DreamFactory-Session-Token for user-specific RBAC. OAuth Bearer tokens always take precedence when both are sent.';
                $schema['type'] = 'boolean';
                $schema['default'] = false;
                break;
        }
    }

    /**
     * Database + file services the admin can attach to this MCP endpoint.
     *
     * @return array<int, array{name: string, label: string}>
     */
    private static function backendServiceChoices(): array
    {
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $sm */
            $sm = app('df.service');
            $fields = ['name', 'label'];
            $services = array_merge(
                $sm->getServiceListByGroup(ServiceTypeGroups::DATABASE, $fields, true),
                $sm->getServiceListByGroup(ServiceTypeGroups::FILE, $fields, true)
            );

            $values = [];
            foreach ($services as $service) {
                $name = (string) ($service['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $label = (string) ($service['label'] ?? $name);
                $values[] = [
                    'name'  => $name,
                    'label' => $label === $name ? $name : $label . ' (' . $name . ')',
                ];
            }

            return $values;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Normalize a configured redirect_uris value into a clean list of URIs.
     *
     * Accepts the array cast, a JSON string (raw column read), or a
     * newline/comma separated string, and drops anything that is not an
     * absolute http/https URL.
     *
     * @param mixed $value
     * @return array
     */
    public static function normalizeRedirectUris($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                ? $decoded
                : preg_split('/[\r\n,]+/', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $uris = [];
        foreach ($value as $uri) {
            if (!is_string($uri)) {
                continue;
            }
            $uri = trim($uri);
            if ($uri === '') {
                continue;
            }
            $parsed = parse_url($uri);
            if (empty($parsed['scheme']) || empty($parsed['host'])) {
                continue;
            }
            if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
                continue;
            }
            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
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

