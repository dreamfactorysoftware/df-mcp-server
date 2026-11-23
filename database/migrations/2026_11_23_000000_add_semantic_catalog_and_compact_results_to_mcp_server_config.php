<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in switches beside semantic_tools. Both default false, so an upgraded
 * MCP service behaves exactly as before until an admin turns one on:
 *  - semantic_catalog: the get_semantics tool, the enriched data model and
 *    aggregate aliases (df-semantic catalog);
 *  - compact_results: compact JSON results, default row/group limits and the
 *    data model index.
 * Adds the columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }
        if (!Schema::hasColumn('mcp_server_config', 'semantic_catalog')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->boolean('semantic_catalog')->default(false)->after('semantic_tools');
            });
        }
        if (!Schema::hasColumn('mcp_server_config', 'compact_results')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->boolean('compact_results')->default(false)->after('semantic_catalog');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'compact_results')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('compact_results');
            });
        }
        if (Schema::hasColumn('mcp_server_config', 'semantic_catalog')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('semantic_catalog');
            });
        }
    }
};
