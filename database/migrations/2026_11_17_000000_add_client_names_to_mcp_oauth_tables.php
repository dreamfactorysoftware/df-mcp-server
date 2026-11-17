<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-registration client names. Every MCP service has one shared OAuth
 * client row, so its `name` only ever held the first registrant's client_name
 * and the request log attributed every client's calls to it.
 *
 * mcp_oauth_client.client_names maps each registered redirect_uri to the
 * client_name the latest /register for that URI sent. The token endpoint
 * copies the name for the code's redirect_uri onto mcp_oauth_access_token,
 * and the request log reads it from there. Both columns are nullable: rows
 * written before this migration fall back to mcp_oauth_client.name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_oauth_client') && !Schema::hasColumn('mcp_oauth_client', 'client_names')) {
            Schema::table('mcp_oauth_client', function (Blueprint $table) {
                $table->text('client_names')->nullable()->after('redirect_uris'); // JSON object: redirect_uri => client_name
            });
        }

        if (Schema::hasTable('mcp_oauth_access_token') && !Schema::hasColumn('mcp_oauth_access_token', 'client_name')) {
            Schema::table('mcp_oauth_access_token', function (Blueprint $table) {
                $table->string('client_name', 200)->nullable()->after('client_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_oauth_access_token', 'client_name')) {
            Schema::table('mcp_oauth_access_token', fn (Blueprint $t) => $t->dropColumn('client_name'));
        }

        if (Schema::hasColumn('mcp_oauth_client', 'client_names')) {
            Schema::table('mcp_oauth_client', fn (Blueprint $t) => $t->dropColumn('client_names'));
        }
    }
};
