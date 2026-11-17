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
        'client_names',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'redirect_uris' => 'array',
        'client_names' => 'array',
    ];

    protected $hidden = ['client_secret'];

    /** Claude's hosted callbacks, always allowed. */
    public const CLAUDE_REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    /** Width of mcp_request_log.client_name and mcp_oauth_access_token.client_name. */
    public const MAX_CLIENT_NAME_LENGTH = 200;

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

    /**
     * A /register client_name as it is stored: a trimmed non-empty string that
     * fits the client_name columns, or null when the caller sent none.
     */
    public static function normalizeClientName(mixed $name): ?string
    {
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        return mb_substr(trim($name), 0, self::MAX_CLIENT_NAME_LENGTH);
    }

    /**
     * client_names after a /register. Every client of a service shares this
     * row, so `name` keeps the first registrant's client_name; this map records
     * each registration's name against the redirect URIs it registered. Names
     * for URIs no longer registered are dropped.
     *
     * @return array<string, string>
     */
    public static function withRegisteredName(array $names, array $requested, array $registered, string $name): array
    {
        $names = array_intersect_key($names, array_flip($registered));
        foreach (array_intersect($requested, $registered) as $uri) {
            $names[$uri] = $name;
        }

        return $names;
    }

    /**
     * The client_name registered for the redirect URI a code was issued to, or
     * null so the caller falls back to the shared `name`, as before client_names.
     *
     * - A URI registered with a name gets that name.
     * - A URI registered without one (before client_names existed, seeded by
     *   /authorize, or added from the admin allowlist) gets null: the shared
     *   `name` is what it was labelled with before.
     * - An unregistered loopback URI is a native app back on a new ephemeral
     *   port (RFC 8252). It takes the name registered for the same host and
     *   path on other ports, but only when every such URI carries that one
     *   name. None, an unnamed one, or two different names give null.
     */
    public function registeredNameFor(string $redirectUri): ?string
    {
        $names = is_array($this->client_names) ? $this->client_names : [];
        if (is_string($names[$redirectUri] ?? null)) {
            return $names[$redirectUri];
        }

        $registered = is_array($this->redirect_uris) ? $this->redirect_uris : [];
        if (in_array($redirectUri, $registered, true)) {
            return null;
        }

        $key = self::loopbackKeyWithoutPort($redirectUri);
        if ($key === null) {
            return null;
        }

        $found = null;
        foreach (array_merge($registered, array_keys($names)) as $uri) {
            if (!is_string($uri) || self::loopbackKeyWithoutPort($uri) !== $key) {
                continue;
            }
            $name = $names[$uri] ?? null;
            if (!is_string($name) || ($found !== null && $name !== $found)) {
                return null;
            }
            $found = $name;
        }

        return $found;
    }

    /**
     * A loopback redirect URI without its port (host as spelled, path, query),
     * or null for any other URI. Two URIs with the same key differ only by port.
     */
    protected static function loopbackKeyWithoutPort(string $uri): ?string
    {
        if (!static::isLoopbackRedirectUri($uri)) {
            return null;
        }
        $p = parse_url($uri);

        return strtolower($p['host']) . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
