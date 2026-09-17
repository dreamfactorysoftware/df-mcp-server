<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tool_style: 'prefixed' (default, existing behaviour) or 'merged'.
 *
 * Nullable with no backfill on purpose — a null column reads as the default
 * 'prefixed', so every existing MCP service keeps emitting the prefixed tool
 * names its clients already reference. Opting in is per service.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }

        Schema::table('mcp_server_config', function (Blueprint $table) {
            if (!Schema::hasColumn('mcp_server_config', 'tool_style')) {
                $table->string('tool_style', 16)->nullable()->after('lazy_mode');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }

        Schema::table('mcp_server_config', function (Blueprint $table) {
            if (Schema::hasColumn('mcp_server_config', 'tool_style')) {
                $table->dropColumn('tool_style');
            }
        });
    }
};
