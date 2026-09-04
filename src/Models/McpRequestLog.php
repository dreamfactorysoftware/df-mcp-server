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
        'mode',
        'catalog_tokens',
        'preamble_saved_per_turn',
        'result_chars_withheld',
        'facade_calls',
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
        'catalog_tokens'          => 'integer',
        'preamble_saved_per_turn' => 'integer',
        'result_chars_withheld'   => 'integer',
        'facade_calls'            => 'integer',
        'bytes_in'    => 'integer',
        'bytes_out'   => 'integer',
        'duration_ms' => 'integer',
        'created_at'  => 'datetime',
    ];
}
