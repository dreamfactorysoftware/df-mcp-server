<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Argument-normalisation counters the daemon reports per tools/call in the
 * X-Mcp-Ledger header (issue #66): how many argument keys were accepted via a
 * camelCase/snake_case alias, and how many unknown keys were rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mcp_request_log') && !Schema::hasColumn('mcp_request_log', 'arg_errors')) {
            Schema::table('mcp_request_log', function (Blueprint $table) {
                $table->integer('arg_errors')->default(0)->after('facade_calls');
                $table->integer('arg_aliases')->default(0)->after('arg_errors');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mcp_request_log', 'arg_errors')) {
            Schema::table('mcp_request_log', fn (Blueprint $t) => $t->dropColumn(['arg_errors', 'arg_aliases']));
        }
    }
};
