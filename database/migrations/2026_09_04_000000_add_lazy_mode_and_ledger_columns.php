<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lazy mode (search/describe/call facade) per MCP service, plus the per-request
 * savings ledger the daemon reports back so usage views can show tokens saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_server_config') && !Schema::hasColumn('mcp_server_config', 'lazy_mode')) {
            Schema::table('mcp_server_config', function (Blueprint $table) {
                $table->string('lazy_mode', 8)->default('auto')->after('disabled_tools');
            });
        }

        if (Schema::hasTable('mcp_request_log') && !Schema::hasColumn('mcp_request_log', 'mode')) {
            Schema::table('mcp_request_log', function (Blueprint $table) {
                $table->string('mode', 16)->nullable()->after('tool_name');
                $table->integer('catalog_tokens')->default(0)->after('mode');
                $table->integer('preamble_saved_per_turn')->default(0)->after('catalog_tokens');
                $table->integer('result_chars_withheld')->default(0)->after('preamble_saved_per_turn');
                $table->integer('facade_calls')->default(0)->after('result_chars_withheld');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_server_config', 'lazy_mode')) {
            Schema::table('mcp_server_config', fn (Blueprint $t) => $t->dropColumn('lazy_mode'));
        }
        if (Schema::hasColumn('mcp_request_log', 'mode')) {
            Schema::table('mcp_request_log', fn (Blueprint $t) => $t->dropColumn([
                'mode', 'catalog_tokens', 'preamble_saved_per_turn', 'result_chars_withheld', 'facade_calls',
            ]));
        }
    }
};
