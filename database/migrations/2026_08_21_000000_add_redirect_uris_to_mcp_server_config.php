<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'redirect_uris')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->text('redirect_uris')->nullable()->after('oauth_client_secret');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'redirect_uris')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropColumn('redirect_uris');
            });
        }
    }
};
