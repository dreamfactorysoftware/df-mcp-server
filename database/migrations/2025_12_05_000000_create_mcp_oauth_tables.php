<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OAuth Clients table
        if (!Schema::hasTable('mcp_oauth_client')) {
            Schema::create('mcp_oauth_client', function (Blueprint $table) {
                $table->increments('id');
                $table->string('client_id', 100)->unique();
                $table->string('client_secret', 255)->nullable();
                $table->string('name', 255)->nullable();
                $table->text('redirect_uris'); // JSON array of allowed redirect URIs
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // OAuth Authorization Codes table
        if (!Schema::hasTable('mcp_oauth_authorization_code')) {
            Schema::create('mcp_oauth_authorization_code', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 100)->unique();
                $table->string('client_id', 100);
                $table->integer('user_id')->unsigned();
                $table->text('redirect_uri');
                $table->string('code_challenge', 255)->nullable();
                $table->string('code_challenge_method', 10)->nullable();
                $table->text('scope')->nullable();
                $table->text('df_session_token'); // Store DreamFactory JWT
                $table->string('user_email', 255);
                $table->string('user_name', 255)->nullable();
                $table->boolean('is_sys_admin')->default(false);
                $table->integer('role_id')->unsigned()->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->nullable();

                // Index for efficient cleanup of expired codes
                $table->index('expires_at');

                $table->foreign('client_id')->references('client_id')->on('mcp_oauth_client')->onDelete('cascade');
            });
        }

        // OAuth Access Tokens table
        if (!Schema::hasTable('mcp_oauth_access_token')) {
            Schema::create('mcp_oauth_access_token', function (Blueprint $table) {
                $table->increments('id');
                $table->string('access_token', 100)->unique();
                $table->string('refresh_token', 100)->unique()->nullable();
                $table->string('client_id', 100);
                $table->integer('user_id')->unsigned();
                $table->text('df_session_token'); // Store DreamFactory JWT
                $table->string('user_email', 255);
                $table->string('user_name', 255)->nullable();
                $table->boolean('is_sys_admin')->default(false);
                $table->integer('role_id')->unsigned()->nullable();
                $table->text('scope')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('refresh_token_expires_at')->nullable();
                $table->timestamp('created_at')->nullable();

                // Indexes for efficient token lookups and cleanup
                $table->index('expires_at');
                $table->index('refresh_token_expires_at');
                $table->index('user_id');

                $table->foreign('client_id')->references('client_id')->on('mcp_oauth_client')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_oauth_access_token');
        Schema::dropIfExists('mcp_oauth_authorization_code');
        Schema::dropIfExists('mcp_oauth_client');
    }
};
