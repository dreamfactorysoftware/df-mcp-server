<?php

namespace DreamFactory\Core\McpServer\Models;

use Illuminate\Database\Eloquent\Model;

class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_client';

    protected $fillable = [
        'client_id',
        'client_secret',
        'name',
        'redirect_uris',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'redirect_uris' => 'array',
    ];

    protected $hidden = ['client_secret'];

    /**
     * Generate a unique client ID
     */
    public static function generateClientId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a client secret
     */
    public static function generateClientSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Find client by client_id
     */
    public static function findByClientId(string $clientId): ?self
    {
        return static::where('client_id', $clientId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Register a new client (dynamic client registration)
     */
    public static function registerClient(array $data): self
    {
        $clientId = $data['client_id'] ?? self::generateClientId();

        // Check if client already exists
        $existing = static::where('client_id', $clientId)->first();
        if ($existing) {
            return $existing;
        }

        return static::create([
            'client_id' => $clientId,
            'client_secret' => $data['client_secret'] ?? self::generateClientSecret(),
            'name' => $data['client_name'] ?? $data['name'] ?? null,
            'redirect_uris' => $data['redirect_uris'] ?? [],
            'is_active' => true,
        ]);
    }

    /**
     * Check if redirect URI is valid for this client
     *
     * Security: Uses strict origin matching to prevent open redirect attacks.
     * For example, "https://evil.com?https://chatgpt.com" would NOT match "https://chatgpt.com"
     */
    public function isValidRedirectUri(string $redirectUri): bool
    {
        $allowedUris = $this->redirect_uris ?? [];

        // If no URIs registered, allow any (for dynamic registration)
        if (empty($allowedUris)) {
            return true;
        }

        $redirectParsed = parse_url($redirectUri);
        if (!$redirectParsed || empty($redirectParsed['scheme']) || empty($redirectParsed['host'])) {
            return false;
        }

        foreach ($allowedUris as $allowedUri) {
            // Exact match is always valid
            if ($redirectUri === $allowedUri) {
                return true;
            }

            // Parse the allowed URI for comparison
            $allowedParsed = parse_url($allowedUri);
            if (!$allowedParsed) {
                continue;
            }

            // Scheme must match exactly
            if (($allowedParsed['scheme'] ?? '') !== ($redirectParsed['scheme'] ?? '')) {
                continue;
            }

            // Host must match exactly
            if (($allowedParsed['host'] ?? '') !== ($redirectParsed['host'] ?? '')) {
                continue;
            }

            // Port must match (null/default ports are considered equal)
            $allowedPort = $allowedParsed['port'] ?? null;
            $redirectPort = $redirectParsed['port'] ?? null;
            if ($allowedPort !== $redirectPort) {
                continue;
            }

            // Path must be a prefix match (allows sub-paths of registered URIs)
            $allowedPath = $allowedParsed['path'] ?? '/';
            $redirectPath = $redirectParsed['path'] ?? '/';

            // Ensure paths end comparison correctly (prevent /callback matching /callbackevil)
            if ($allowedPath === $redirectPath) {
                return true;
            }

            // Allow sub-paths: /callback allows /callback/something but not /callbackevil
            if (str_starts_with($redirectPath, rtrim($allowedPath, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
