<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named semantic tools switch. Default false: an MCP service offers one tool
 * per approved metric / verified query (df-semantic) only after an admin opts
 * in. Adds the column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'semantic_tools')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->boolean('semantic_tools')->default(false)->after('allow_writes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'semantic_tools')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('semantic_tools');
            });
        }
    }
};
