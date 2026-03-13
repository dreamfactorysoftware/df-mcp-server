<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_custom_tools')) {
            Schema::table('mcp_custom_tools', function (Blueprint $table) {
                $table->string('tool_type', 20)->default('api')->after('service_id');
                $table->text('formula')->nullable()->after('headers');
                $table->string('http_method', 10)->nullable()->change();
                $table->string('url', 2048)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mcp_custom_tools')) {
            Schema::table('mcp_custom_tools', function (Blueprint $table) {
                $table->dropColumn(['tool_type', 'formula']);
                $table->string('http_method', 10)->nullable(false)->change();
                $table->string('url', 2048)->nullable(false)->change();
            });
        }
    }
};
