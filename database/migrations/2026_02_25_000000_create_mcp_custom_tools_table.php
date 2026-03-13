<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_custom_tools')) {
            Schema::create('mcp_custom_tools', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('service_id')->unsigned()->index();
                $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                $table->string('name', 100);
                $table->string('description', 1000);
                $table->string('http_method', 10);
                $table->string('url', 2048);
                $table->text('parameters')->nullable();
                $table->text('headers')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['service_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_custom_tools');
    }
};
