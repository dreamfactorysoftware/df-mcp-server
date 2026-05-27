<?php

declare(strict_types=1);

namespace DreamFactory\Core\McpServer\Models;

use Illuminate\Database\Eloquent\Model;

class McpRequestLog extends Model
{
    protected $table = 'mcp_request_log';

    public $timestamps = false;

    protected $fillable = [
        'service_id',
        'user_id',
        'role_id',
        'app_id',
        'client_id',
        'client_name',
        'method',
        'tool_name',
        'bytes_in',
        'bytes_out',
        'duration_ms',
        'status',
        'error_message',
        'request_id',
    ];

    protected $casts = [
        'service_id'  => 'integer',
        'user_id'     => 'integer',
        'role_id'     => 'integer',
        'app_id'      => 'integer',
        'bytes_in'    => 'integer',
        'bytes_out'   => 'integer',
        'duration_ms' => 'integer',
        'created_at'  => 'datetime',
    ];
}
