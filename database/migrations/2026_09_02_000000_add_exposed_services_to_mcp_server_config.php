<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }

        Schema::table('mcp_server_config', function (Blueprint $table) {
            if (!Schema::hasColumn('mcp_server_config', 'exposed_services')) {
                $table->text('exposed_services')->nullable()->after('disabled_tools');
            }
            if (!Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                $table->boolean('scope_tools')->nullable()->after('exposed_services');
            }
        });

        $this->backfillExistingCatalogs();
    }

    public function down(): void
    {
        if (!Schema::hasTable('mcp_server_config')) {
            return;
        }

        Schema::table('mcp_server_config', function (Blueprint $table) {
            if (Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                $table->dropColumn('scope_tools');
            }
            if (Schema::hasColumn('mcp_server_config', 'exposed_services')) {
                $table->dropColumn('exposed_services');
            }
        });
    }

    /**
     * Empty Exposed Services always means "no auto DB/file tools". Snapshot
     * the current database+file service names onto existing MCP rows so their
     * tools/list does not shrink on upgrade. If we cannot resolve that list,
     * fall back to scope_tools=false (legacy instance-wide catalog).
     */
    private function backfillExistingCatalogs(): void
    {
        if (!Schema::hasColumn('mcp_server_config', 'exposed_services')) {
            return;
        }

        $names = $this->currentBackendServiceNames();
        $rows = DB::table('mcp_server_config')->get();

        foreach ($rows as $row) {
            $current = $row->exposed_services ?? null;
            $decoded = is_string($current) ? json_decode($current, true) : $current;
            if (is_array($decoded) && $decoded !== []) {
                continue;
            }

            if ($names !== []) {
                DB::table('mcp_server_config')
                    ->where('service_id', $row->service_id)
                    ->update(['exposed_services' => json_encode(array_values($names))]);
            } elseif (Schema::hasColumn('mcp_server_config', 'scope_tools')) {
                DB::table('mcp_server_config')
                    ->where('service_id', $row->service_id)
                    ->update(['scope_tools' => false]);
            }
        }
    }

    /**
     * @return string[]
     */
    private function currentBackendServiceNames(): array
    {
        try {
            /** @var \DreamFactory\Core\Services\ServiceManager $sm */
            $sm = app('df.service');
            $names = [];
            foreach (['Database', 'File'] as $group) {
                foreach ($sm->getServiceListByGroup($group, ['name'], true) as $service) {
                    $name = (string) ($service['name'] ?? '');
                    if ($name !== '') {
                        $names[] = $name;
                    }
                }
            }

            return array_values(array_unique($names));
        } catch (\Throwable $e) {
            return [];
        }
    }
};
