<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_request_log')) {
            Schema::create('mcp_request_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('service_id')->unsigned();
                $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                $table->integer('user_id')->unsigned()->nullable();
                $table->integer('role_id')->unsigned()->nullable();
                $table->integer('app_id')->unsigned()->nullable();
                $table->string('client_id', 100)->nullable();
                $table->string('client_name', 200)->nullable();
                $table->string('method', 50);
                $table->string('tool_name', 200)->nullable();
                $table->integer('bytes_in')->default(0);
                $table->integer('bytes_out')->default(0);
                $table->integer('duration_ms')->default(0);
                $table->string('status', 20)->default('success');
                $table->text('error_message')->nullable();
                $table->char('request_id', 36)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['service_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
                $table->index('request_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_request_log');
    }
};
