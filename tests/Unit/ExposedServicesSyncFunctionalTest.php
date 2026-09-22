<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Functional coverage for the rename/delete rewrite: a real sqlite database
 * behind the DB/Cache facades, no full DreamFactory app. Runs only against a
 * vendor tree that ships Illuminate (standalone checkouts cover the pure
 * transforms in ExposedServicesSyncTest and the wiring in
 * ServiceRenameDeleteSyncWiringTest).
 */
class ExposedServicesSyncFunctionalTest extends TestCase
{
    private ?object $container = null;

    protected function setUp(): void
    {
        if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            $this->markTestSkipped(
                'ExposedServicesSync writes through the DB facade — this test needs the full DreamFactory vendor tree.'
            );
        }
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }

        $container = new \Illuminate\Container\Container();
        $container->instance('config', new \Illuminate\Config\Repository([
            'cache' => [
                'default' => 'array',
                'stores'  => ['array' => ['driver' => 'array', 'serialize' => false]],
            ],
        ]));

        $capsule = new \Illuminate\Database\Capsule\Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('cache', new \Illuminate\Cache\CacheManager($container));

        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $this->container = $container;

        $schema = $capsule->getDatabaseManager()->connection()->getSchemaBuilder();
        $schema->create('service', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type');
        });
        $schema->create('mcp_server_config', function ($table) {
            $table->integer('service_id');
            $table->text('exposed_services')->nullable();
            $table->boolean('scope_tools')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        \Illuminate\Support\Facades\DB::table('service')->insert([
            ['id' => 1, 'name' => 'reports', 'type' => 'mcp'],
            ['id' => 2, 'name' => 'sysmcp', 'type' => 'system_mcp'],
            ['id' => 3, 'name' => 'retired', 'type' => 'mcp'],
            ['id' => 10, 'name' => 'mysql', 'type' => 'mysql'],
            ['id' => 11, 'name' => 'files', 'type' => 'local_file'],
        ]);
        \Illuminate\Support\Facades\DB::table('mcp_server_config')->insert([
            // Live mcp row; stored spelling differs in case from the service.
            ['service_id' => 1, 'exposed_services' => '["MySQL","files"]', 'deleted_at' => null],
            // system_mcp row: exposed_services is meaningless there and the
            // sync must never touch other types' configs.
            ['service_id' => 2, 'exposed_services' => '["mysql"]', 'deleted_at' => null],
            // Soft-deleted mcp row: dead config stays as-is.
            ['service_id' => 3, 'exposed_services' => '["mysql"]', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        // Runs after a skipped setUp too, where Illuminate is absent.
        if ($this->container !== null) {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
            $this->container = null;
        }
    }

    /** @return string[]|null decoded exposed_services for one config row */
    private function exposedFor(int $serviceId): ?array
    {
        $raw = \Illuminate\Support\Facades\DB::table('mcp_server_config')
            ->where('service_id', $serviceId)
            ->value('exposed_services');

        return $raw === null ? null : json_decode($raw, true);
    }

    public function testRenameRewritesLiveMcpRowsCaseInsensitivelyAndPurgesTheirCache(): void
    {
        \Illuminate\Support\Facades\Cache::forever('service_mgr:reports', 'stale-config');
        \Illuminate\Support\Facades\Cache::forever('service_mgr:sysmcp', 'untouched');
        \Illuminate\Support\Facades\Cache::forever('service_mgr:name_type_map', 'stale-map');

        \DreamFactory\Core\McpServer\Support\ExposedServicesSync::serviceRenamed('mysql', 'warehouse');

        $this->assertSame(['warehouse', 'files'], $this->exposedFor(1));
        $this->assertSame(['mysql'], $this->exposedFor(2), 'system_mcp rows must never be rewritten');
        $this->assertSame(['mysql'], $this->exposedFor(3), 'soft-deleted rows must never be rewritten');

        // The rewritten service's forever-cached config and the map keys are
        // forgotten; services the rewrite did not touch keep theirs.
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('service_mgr:reports'));
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('service_mgr:name_type_map'));
        $this->assertSame('untouched', \Illuminate\Support\Facades\Cache::get('service_mgr:sysmcp'));
    }

    public function testDeleteRemovesTheNameAndAnEmptiedListStaysEmpty(): void
    {
        \DreamFactory\Core\McpServer\Support\ExposedServicesSync::serviceDeleted('files');
        $this->assertSame(['MySQL'], $this->exposedFor(1));

        \DreamFactory\Core\McpServer\Support\ExposedServicesSync::serviceDeleted('MYSQL');
        $this->assertSame(
            [],
            $this->exposedFor(1),
            'removing the last backend must store [] — "expose nothing" — not NULL or a fallback'
        );
        $this->assertSame(['mysql'], $this->exposedFor(2));
        $this->assertSame(['mysql'], $this->exposedFor(3));
    }

    public function testRenameNobodyExposesTouchesNothing(): void
    {
        \Illuminate\Support\Facades\Cache::forever('service_mgr:reports', 'kept');

        \DreamFactory\Core\McpServer\Support\ExposedServicesSync::serviceRenamed('postgres', 'pg2');

        $this->assertSame(['MySQL', 'files'], $this->exposedFor(1));
        $this->assertSame(
            'kept',
            \Illuminate\Support\Facades\Cache::get('service_mgr:reports'),
            'no rewrite means no cache purge'
        );
    }
}
