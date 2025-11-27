<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            Schema::create(
                'mcp_server_config',
                function (Blueprint $table) {
                    $table->integer('service_id')->unsigned()->primary();
                    $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                    $table->string('api_key', 255)->index();
                    $table->string('api_name', 255)->index();
                    $table->timestamps();
                    $table->softDeletes();
            });
        }
    }


    public function down(): void
    {
        Schema::dropIfExists('mcp_server_config');
    }
};

