<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service switch: require a role grant on the MCP service itself before a
 * non-admin identity may connect to /mcp/{service}.
 *
 * Column default false so every EXISTING service keeps today's open-door
 * behaviour on upgrade. New services default to true via the model's creating
 * hook. This migration never writes role rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'require_role_access')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->boolean('require_role_access')->default(false)->after('allow_api_key_auth');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'require_role_access')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('require_role_access');
            });
        }
    }
};
