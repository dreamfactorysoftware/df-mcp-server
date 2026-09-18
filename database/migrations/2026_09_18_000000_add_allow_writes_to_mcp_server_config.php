<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-wide writes switch. Default true: every existing MCP service keeps
 * its write verbs until an admin turns the flag off. Adds the column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'allow_writes')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->boolean('allow_writes')->default(true)->after('tool_style');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'allow_writes')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('allow_writes');
            });
        }
    }
};
