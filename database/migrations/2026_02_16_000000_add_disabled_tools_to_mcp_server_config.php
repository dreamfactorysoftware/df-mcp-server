<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'disabled_tools')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->text('disabled_tools')->nullable()->after('custom_login_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'disabled_tools')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('disabled_tools');
            });
        }
    }
};
