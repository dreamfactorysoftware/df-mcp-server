<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'app_id')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->integer('app_id')->unsigned()->nullable()->after('api_name');
                $table->foreign('app_id')->references('id')->on('app')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'app_id')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->dropForeign(['app_id']);
                $table->dropColumn('app_id');
            });
        }
    }
};
