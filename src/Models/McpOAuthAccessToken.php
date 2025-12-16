<?php

namespace DreamFactory\Core\McpServer\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class McpOAuthAccessToken extends Model
{
    const UPDATED_AT = null;

    protected $table = 'mcp_oauth_access_token';

    protected $fillable = [
        'access_token',
        'refresh_token',
        'client_id',
        'user_id',
        'df_session_token',
        'user_email',
        'user_name',
        'scope',
        'expires_at',
        'refresh_token_expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
    ];

    protected $hidden = ['df_session_token'];

    /**
     * Access token lifetime in hours
     */
    const ACCESS_TOKEN_LIFETIME_HOURS = 1;

    /**
     * Refresh token lifetime in days
     */
    const REFRESH_TOKEN_LIFETIME_DAYS = 7;

    /**
     * Generate a new access token
     */
    public static function createToken(array $data): self
    {
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));

        return static::create([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'client_id' => $data['client_id'],
            'user_id' => $data['user_id'],
            'df_session_token' => $data['df_session_token'],
            'user_email' => $data['user_email'],
            'user_name' => $data['user_name'] ?? null,
            'scope' => $data['scope'] ?? null,
            'expires_at' => Carbon::now()->addHours(self::ACCESS_TOKEN_LIFETIME_HOURS),
            'refresh_token_expires_at' => Carbon::now()->addDays(self::REFRESH_TOKEN_LIFETIME_DAYS),
        ]);
    }

    /**
     * Find valid access token
     */
    public static function findValidAccessToken(string $token): ?self
    {
        return static::where('access_token', $token)
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Find valid refresh token
     */
    public static function findValidRefreshToken(string $token): ?self
    {
        return static::where('refresh_token', $token)
            ->where('refresh_token_expires_at', '>', Carbon::now())
            ->first();
    }

    /**
     * Refresh the token (generate new access token, keep refresh token)
     */
    public function refresh(): self
    {
        $this->access_token = bin2hex(random_bytes(32));
        $this->expires_at = Carbon::now()->addHours(self::ACCESS_TOKEN_LIFETIME_HOURS);
        $this->save();

        return $this;
    }

    /**
     * Check if access token is expired
     */
    public function isExpired(): bool
    {
        return Carbon::now()->greaterThan($this->expires_at);
    }

    /**
     * Revoke the token
     */
    public function revoke(): bool
    {
        return $this->delete();
    }

    /**
     * Get the DreamFactory session token (for API calls)
     */
    public function getDfSessionToken(): string
    {
        return $this->df_session_token;
    }

    /**
     * Get the client associated with this token
     */
    public function client()
    {
        return $this->belongsTo(McpOAuthClient::class, 'client_id', 'client_id');
    }
}
