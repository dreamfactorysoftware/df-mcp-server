<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Support\RoleSession;
use PHPUnit\Framework\TestCase;

/**
 * Functional (needs the DreamFactory vendor tree; skipped standalone): the
 * catalog preview evaluates AvailableServices::resolve() and
 * Session::getServicePermissions() AS the previewed role, through the REAL
 * df-core Session over a real Illuminate session store, and then hands the
 * admin their own session back — including when the callback throws.
 */
class RoleSessionTest extends TestCase
{
    public const ROLE = [
        'id' => 5,
        'name' => 'mcp-app-role',
        'is_active' => true,
        'role_service_access_by_role_id' => [
            ['service_id' => 1, 'service' => 'mysql', 'component' => '*', 'verb_mask' => 1, 'requestor_mask' => 1],
            ['service_id' => 3, 'service' => 'files', 'component' => '*', 'verb_mask' => 31, 'requestor_mask' => 1],
        ],
    ];

    public function testCallbackSeesTheRoleAndTheAdminGetsTheirSessionBack(): void
    {
        $this->bootMinimalLaravel();
        $this->establishAdminSession();
        $before = \Session::all();

        $this->assertTrue(\DreamFactory\Core\Utility\Session::isSysAdmin());
        $this->assertSame(['mysql', 'postgres'], $this->resolveNames(['exposed_services' => ['mysql', 'postgres']]));

        $seen = RoleSession::run(self::ROLE, 42, fn () => [
            'admin'    => \DreamFactory\Core\Utility\Session::isSysAdmin(),
            'names'    => $this->resolveNames(['exposed_services' => ['mysql', 'postgres']]),
            'mysql'    => \DreamFactory\Core\Utility\Session::getServicePermissions('mysql'),
            'files'    => \DreamFactory\Core\Utility\Session::getServicePermissions('files'),
            'postgres' => \DreamFactory\Core\Utility\Session::getServicePermissions('postgres'),
            'app'      => \Session::get('app.id'),
        ]);

        // Inside: no admin lift, role ∩ exposure, verb masks per backend.
        $this->assertSame(
            ['admin' => false, 'names' => ['mysql'], 'mysql' => 1, 'files' => 31, 'postgres' => 0, 'app' => 42],
            $seen
        );

        // After: the admin's session, untouched.
        $this->assertTrue(\DreamFactory\Core\Utility\Session::isSysAdmin());
        $this->assertSame(1, \Session::get('app.id'));
        $this->assertNull(\Session::get('role.services'));
        $this->assertSame($before, \Session::all());
    }

    public function testSessionIsRestoredWhenTheCallbackThrows(): void
    {
        $this->bootMinimalLaravel();
        $this->establishAdminSession();
        $before = \Session::all();

        try {
            RoleSession::run(self::ROLE, 42, function (): void {
                throw new \RuntimeException('daemon down');
            });
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('daemon down', $e->getMessage());
        }

        $this->assertTrue(\DreamFactory\Core\Utility\Session::isSysAdmin());
        $this->assertSame($before, \Session::all());
    }

    // ---------------------------------------------------------------
    // Minimal Laravel container (vendor tree only) — as ApiKeyRoleScopingTest
    // ---------------------------------------------------------------

    protected function tearDown(): void
    {
        if (class_exists(\Illuminate\Container\Container::class, false)) {
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
            \Illuminate\Container\Container::setInstance(null);
        }

        parent::tearDown();
    }

    private function bootMinimalLaravel(): void
    {
        if (!class_exists(\Illuminate\Container\Container::class)
            || !class_exists(\DreamFactory\Core\Utility\Session::class)
        ) {
            $this->markTestSkipped(
                'Functional role session needs the DreamFactory vendor tree '
                . '(Illuminate container + df-core Session); run inside a DF app checkout.'
            );
        }

        $container = new \Illuminate\Container\Container();
        \Illuminate\Container\Container::setInstance($container);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);

        $container->instance('config', new \Illuminate\Config\Repository([
            'mcp' => ['scope_tools' => true],
            'df'  => ['default_cache_ttl' => 300],
        ]));
        $container->instance('session', new \Illuminate\Session\Store(
            'testing',
            new \Illuminate\Session\ArraySessionHandler(120)
        ));
        $container->instance('log', new class {
            public function __call(string $method, array $args): void
            {
            }
        });
        // Role::getCachedInfo($id, 'is_active') inside getServicePermissions():
        // serve the cached role without touching the database.
        $container->instance('cache', new class {
            public function remember(string $key, $ttl, \Closure $callback): array
            {
                return RoleSessionTest::ROLE;
            }
        });
        $container->instance('df.service', new class {
            public function getServiceListByGroup($group, $fields = null, $onlyActive = false): array
            {
                if ($group === \DreamFactory\Core\Enums\ServiceTypeGroups::DATABASE) {
                    return [
                        ['id' => 1, 'name' => 'mysql', 'label' => 'MySQL', 'type' => 'mysql'],
                        ['id' => 2, 'name' => 'postgres', 'label' => 'PostgreSQL', 'type' => 'pgsql'],
                    ];
                }

                return [
                    ['id' => 3, 'name' => 'files', 'label' => 'Files', 'type' => 'local_file'],
                ];
            }
        });

        foreach (['Session', 'Cache', 'Config'] as $alias) {
            if (!class_exists($alias, false)) {
                class_alias('Illuminate\\Support\\Facades\\' . $alias, $alias);
            }
        }
    }

    /** A sysadmin JWT session with no user-app-role rows (what df-core caches for admins). */
    private function establishAdminSession(): void
    {
        \DreamFactory\Core\Utility\Session::setUserInfo([
            'id' => 9, 'name' => 'admin', 'email' => 'admin@example.com', 'is_sys_admin' => true,
        ]);
        \Session::put('has_role', false);
        \Session::put('app.id', 1);
    }

    /**
     * @param array<string, mixed> $mcpConfig
     * @return string[]
     */
    private function resolveNames(array $mcpConfig): array
    {
        $resolved = \DreamFactory\Core\McpServer\Utility\AvailableServices::resolve('mcp1', $mcpConfig);

        return array_values(array_map(static fn ($s) => (string) $s['name'], $resolved));
    }
}
