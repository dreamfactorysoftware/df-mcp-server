<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_custom_tools') && Schema::hasColumn('mcp_custom_tools', 'formula')) {
            Schema::table('mcp_custom_tools', function (Blueprint $table) {
                $table->renameColumn('formula', 'function');
            });

            // Update tool_type values from 'formula' to 'function'
            \DB::table('mcp_custom_tools')
                ->where('tool_type', 'formula')
                ->update(['tool_type' => 'function']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mcp_custom_tools') && Schema::hasColumn('mcp_custom_tools', 'function')) {
            Schema::table('mcp_custom_tools', function (Blueprint $table) {
                $table->renameColumn('function', 'formula');
            });

            \DB::table('mcp_custom_tools')
                ->where('tool_type', 'function')
                ->update(['tool_type' => 'formula']);
        }
    }
};
