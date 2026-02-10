<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'api_name')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropIndex(['api_name']);
                $table->dropColumn('api_name');
                if (Schema::hasColumn('mcp_server_config', 'api_key')) {
                    $table->dropColumn('api_key');
                }
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('mcp_server_config', 'api_name')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->string('api_name', 255)->nullable()->index()->after('service_id');
            });
        }
    }
};
