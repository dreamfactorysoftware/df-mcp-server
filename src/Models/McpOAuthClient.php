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

    /** Claude's hosted callbacks, always allowed. */
    public const CLAUDE_REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

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
     * RFC 8252 Section 7.3 - is this host the loopback interface?
     *
     * Exact membership only. A suffix or substring test would let
     * "localhost.evil.com" or "127.0.0.1.evil.com" qualify. parse_url()
     * returns IPv6 literals bracketed ("[::1]"), so brackets are stripped.
     */
    protected static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        // 127.0.0.0/8 is loopback, but only as a real IPv4 literal so that a
        // hostname merely beginning with "127." cannot qualify.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        return false;
    }

    /**
     * The redirect_uris a dynamic registration (/register, unauthenticated) may
     * leave on the shared client: Claude's callbacks, the admin-configured
     * allowlist, and loopback http URIs (RFC 8252 native apps: Claude Desktop,
     * Claude Code, Cursor, VS Code), whether just requested or already stored.
     * Any other caller-supplied URI is dropped, so an anonymous caller cannot
     * add an origin of their own and have authorization codes delivered there.
     * Previously stored non-loopback URIs are pruned for the same reason.
     *
     * @return string[]
     */
    public static function registrableRedirectUris(array $requested, array $existing, array $configured): array
    {
        $loopback = array_filter(
            array_merge($existing, $requested),
            fn ($uri) => is_string($uri) && static::isLoopbackRedirectUri($uri)
        );

        return array_values(array_unique(array_merge(self::CLAUDE_REDIRECT_URIS, $configured, $loopback)));
    }

    /** http:// on a loopback host: the RFC 8252 native-app redirect. */
    public static function isLoopbackRedirectUri(string $uri): bool
    {
        $p = parse_url($uri);

        return is_array($p)
            && strtolower($p['scheme'] ?? '') === 'http'
            && static::isLoopbackHost($p['host'] ?? '');
    }

    /**
     * Check if redirect URI is valid for this client
     *
     * Security: Uses strict origin matching to prevent open redirect attacks.
     * For example, "https://evil.com?https://chatgpt.com" would NOT match "https://chatgpt.com"
     */
    public function isValidRedirectUri(string $redirectUri, array $additionalUris = []): bool
    {
        $allowedUris = array_values(array_unique(array_merge(
            $this->redirect_uris ?? [],
            $additionalUris
        )));

        // If no URIs registered, allow any (for dynamic registration)
        if (empty($allowedUris)) {
            return true;
        }

        return self::uriMatchesList($allowedUris, $redirectUri);
    }

    /**
     * Match a redirect URI against an explicit allowlist using the same strict
     * origin rules as isValidRedirectUri(). An empty list matches nothing here —
     * the permissive empty-list case belongs to the caller.
     */
    public static function uriMatchesList(array $allowedUris, string $redirectUri): bool
    {
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

            // RFC 8252 Section 7.3: a native app (Claude Code, Cursor, MCP
            // Inspector) listens on an EPHEMERAL loopback port that it does not
            // know at registration time and that changes between runs, so the
            // authorization server MUST allow any port for loopback redirect
            // URIs, and clients may spell the host as either the name or the IP
            // literal. Both sides must be http on a loopback host, so this can
            // never relax matching for a remote origin.
            $isLoopback = ($redirectParsed['scheme'] ?? '') === 'http'
                && ($allowedParsed['scheme'] ?? '') === 'http'
                && static::isLoopbackHost($redirectParsed['host'] ?? '')
                && static::isLoopbackHost($allowedParsed['host'] ?? '');

            if (!$isLoopback) {
                // Host must match exactly (host names are case-insensitive)
                if (strtolower($allowedParsed['host'] ?? '') !== strtolower($redirectParsed['host'] ?? '')) {
                    continue;
                }

                // Port must match (null/default ports are considered equal)
                $allowedPort = $allowedParsed['port'] ?? null;
                $redirectPort = $redirectParsed['port'] ?? null;
                if ($allowedPort !== $redirectPort) {
                    continue;
                }
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
