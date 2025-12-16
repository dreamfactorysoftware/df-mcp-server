<?php

namespace DreamFactory\Core\McpServer\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class McpOAuthAuthorizationCode extends Model
{
    const UPDATED_AT = null;

    protected $table = 'mcp_oauth_authorization_code';

    protected $fillable = [
        'code',
        'client_id',
        'user_id',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'scope',
        'df_session_token',
        'user_email',
        'user_name',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    protected $hidden = ['df_session_token'];

    /**
     * Code expiration time in minutes
     */
    const CODE_LIFETIME_MINUTES = 10;

    /**
     * Generate a new authorization code
     */
    public static function createCode(array $data): self
    {
        // Clean up expired codes first
        static::cleanExpired();

        $code = bin2hex(random_bytes(32));

        return static::create([
            'code' => $code,
            'client_id' => $data['client_id'],
            'user_id' => $data['user_id'],
            'redirect_uri' => $data['redirect_uri'],
            'code_challenge' => $data['code_challenge'] ?? null,
            'code_challenge_method' => $data['code_challenge_method'] ?? null,
            'scope' => $data['scope'] ?? null,
            'df_session_token' => $data['df_session_token'],
            'user_email' => $data['user_email'],
            'user_name' => $data['user_name'] ?? null,
            'expires_at' => Carbon::now()->addMinutes(self::CODE_LIFETIME_MINUTES),
        ]);
    }

    /**
     * Find and validate an authorization code
     */
    public static function findValidCode(string $code): ?self
    {
        return static::where('code', $code)
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Clean up expired authorization codes
     */
    public static function cleanExpired(): int
    {
        return static::where('expires_at', '<', Carbon::now())->delete();
    }

    /**
     * Consume the authorization code (use once and delete)
     */
    public function consume(): bool
    {
        return $this->delete();
    }

    /**
     * Verify PKCE code_verifier against stored code_challenge
     */
    public function verifyCodeChallenge(string $codeVerifier): bool
    {
        if (empty($this->code_challenge)) {
            return true; // No PKCE required
        }

        if ($this->code_challenge_method === 'S256') {
            $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
            return hash_equals($this->code_challenge, $computed);
        }

        // Plain method
        return hash_equals($this->code_challenge, $codeVerifier);
    }

    /**
     * Get the client associated with this code
     */
    public function client()
    {
        return $this->belongsTo(McpOAuthClient::class, 'client_id', 'client_id');
    }
}
