<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddScmFieldsToMcpCustomTools extends Migration
{
    public function up()
    {
        // SQL Server does not allow potential cascading loops, so set no action there.
        $driver = Schema::getConnection()->getDriverName();
        $onDelete = (('sqlsrv' === $driver) ? 'no action' : 'set null');

        Schema::table('mcp_custom_tools', function (Blueprint $table) use ($onDelete) {
            $table->unsignedInteger('storage_service_id')->nullable()->after('function');
            $table->string('scm_repository')->nullable()->after('storage_service_id');
            $table->string('scm_reference')->nullable()->after('scm_repository');
            $table->string('storage_path')->nullable()->after('scm_reference');

            $table->foreign('storage_service_id')
                ->references('id')
                ->on('service')
                ->onDelete($onDelete);
        });
    }

    public function down()
    {
        Schema::table('mcp_custom_tools', function (Blueprint $table) {
            $table->dropForeign(['storage_service_id']);
            $table->dropColumn(['storage_service_id', 'scm_repository', 'scm_reference', 'storage_path']);
        });
    }
}
