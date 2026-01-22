<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'allow_api_key_auth')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->boolean('allow_api_key_auth')->default(false)->after('custom_login_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'allow_api_key_auth')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('allow_api_key_auth');
            });
        }
    }
};
