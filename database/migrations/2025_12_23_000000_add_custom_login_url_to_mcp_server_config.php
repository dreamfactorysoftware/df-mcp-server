<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'custom_login_url')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->string('custom_login_url', 500)->nullable()->after('oauth_client_secret');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'custom_login_url')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('custom_login_url');
            });
        }
    }
};
