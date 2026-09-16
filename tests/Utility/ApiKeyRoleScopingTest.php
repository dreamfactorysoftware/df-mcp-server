<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use PHPUnit\Framework\TestCase;

/**
 * 7.7.1 integration: an API-key-only client (session established from the
 * key's APP role, no user) must get a tools/list catalog that honors BOTH
 * the app role's service access AND the MCP service's exposed_services
 * scoping.
 *
 * Two layers:
 *
 * 1. Source-wiring assertions (run everywhere): the controller establishes
 *    the DF session from the key's app BEFORE resolving the catalog through
 *    the shared AvailableServices helper, and no alternative catalog path
 *    exists for key-authenticated requests.
 *
 * 2. Functional tests (need the DreamFactory vendor tree; skipped in a
 *    standalone checkout): REAL df-core Session (setUserInfo/setRoleInfo/
 *    isSysAdmin/get over a real Illuminate session store) composed with the
 *    REAL AvailableServices::resolve(). Only the DB-backed halves of
 *    Session::setSessionData() (App::getCachedInfo / Role::getCachedInfo)
 *    are emulated, by calling the same setRoleInfo()/setUserInfo() that
 *    setSessionData() itself calls with the cached records:
 *
 *        setSessionData($appId, null)        // key-only
 *          -> roleId = app.role_id           // ApiKeyAuth requires one
 *          -> setUserInfo(null)              // no user
 *          -> put('app.id', $appId)
 *          -> setRoleInfo(Role::getCachedInfo($roleId))
 *
 *    With no user in session, Session::isSysAdmin() is false, so the
 *    catalog is filtered by role.services — then exposed_services scoping
 *    applies on top. A layered admin JWT (user.is_sys_admin) can lift the
 *    role filter, but never the exposure filter.
 */
class ApiKeyRoleScopingTest extends TestCase
{
    // ---------------------------------------------------------------
    // Source wiring: session-from-app-role feeds the shared resolver
    // ---------------------------------------------------------------

    public function testKeyAuthSessionIsEstablishedBeforeCatalogResolution(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpStreamController.php');

        $setApiKeyPos = strpos($src, 'SessionUtilities::setApiKey(');
        $setDataPos = strpos($src, "SessionUtilities::setSessionData(\$auth['app_id'], \$auth['user_id'])");
        $resolvePos = strpos($src, 'AvailableServices::resolve($mcpService');

        $this->assertNotFalse($setApiKeyPos, 'key path must record the API key in the session');
        $this->assertNotFalse($setDataPos, 'key path must seed the session from the key app');
        $this->assertNotFalse($resolvePos, 'catalog must come from the shared helper');

        // resolve() reads role.services/isSysAdmin from the session, so the
        // key-app session must exist before it runs.
        $this->assertLessThan($resolvePos, $setApiKeyPos, 'setApiKey must precede resolve()');
        $this->assertLessThan($resolvePos, $setDataPos, 'setSessionData must precede resolve()');

        // No second, unscoped catalog path for any auth mode.
        $this->assertSame(
            1,
            substr_count($src, 'AvailableServices::resolve('),
            'exactly one catalog resolution call site in the controller'
        );
        $this->assertStringNotContainsString('getServiceListByGroup', $src);
    }

    public function testResolveComposesRoleFilterWithExposureFilter(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Utility/AvailableServices.php');

        // catalog() applies the session's role; scope() applies
        // exposed_services on the catalog() result — composition, not either/or.
        $this->assertStringContainsString('SessionUtilities::isSysAdmin()', $src);
        $this->assertStringContainsString("SessionUtilities::get('role.services')", $src);
        $this->assertMatchesRegularExpression(
            '/\$services\s*=\s*self::catalog\(\);.*self::scope\(\$services/s',
            $src,
            'resolve() must scope the role-filtered catalog'
        );

        // The df-core "null service_id row means every service" convention
        // must be preserved for all-services app roles.
        $this->assertMatchesRegularExpression(
            '/\$sid\s*===\s*null\s*\|\|\s*\$sid\s*===\s*0\s*\|\|\s*\$sid\s*===\s*\'\'/',
            $src
        );
    }

    public function testProxyAlwaysShipsTheScopedCatalogToTheDaemon(): void
    {
        // The daemon only skips rediscovery when PHP hands it a catalog, so
        // the proxy must send one on every request shape — including [].
        // Since the system_mcp merge, both POST paths build their body through
        // the shared envelope() helper (which also carries _mcpSecretFields
        // for the System API daemon), so the catalog is attached in exactly
        // one place and neither path can drop it independently.
        $src = file_get_contents(__DIR__ . '/../../src/Client/McpDaemonClient.php');

        $this->assertSame(
            1,
            substr_count($src, "'_mcpAvailableServices' => \$availableServices ?: []"),
            'the shared envelope() builder attaches the catalog'
        );
        $this->assertSame(
            2,
            substr_count($src, '$this->envelope('),
            'both the proxy and the stateless RPC bridge envelope the catalog'
        );
        $this->assertStringContainsString(
            "\$headers['X-Mcp-Available-Services'] = json_encode(array_values(\$availableServices))",
            $src
        );
    }

    // ---------------------------------------------------------------
    // Functional: real df-core Session + real AvailableServices
    // ---------------------------------------------------------------

    public function testKeyOnlyRestrictedAppRoleIntersectsRoleAndExposure(): void
    {
        $this->bootMinimalLaravel();
        $this->establishKeyOnlySession([['service_id' => 1], ['service_id' => 3]]);

        $names = $this->resolveNames(['exposed_services' => ['mysql', 'postgres']]);

        // Role allows mysql(1)+files(3); exposure allows mysql+postgres.
        // postgres is blocked by the app role, files by the exposure list.
        $this->assertSame(['mysql'], $names);
    }

    public function testKeyOnlyAllServicesAppRoleIsStillScopedToExposure(): void
    {
        $this->bootMinimalLaravel();
        // df-core convention: a null service_id row grants every service.
        $this->establishKeyOnlySession([['service_id' => null]]);

        $names = $this->resolveNames(['exposed_services' => ['postgres']]);

        $this->assertSame(['postgres'], $names);
    }

    public function testKeyOnlyDefaultScopingWithNoExposureYieldsNoBackends(): void
    {
        $this->bootMinimalLaravel(); // MCP_SCOPE_TOOLS default: true
        $this->establishKeyOnlySession([['service_id' => 1], ['service_id' => 3]]);

        $this->assertSame([], $this->resolveNames([]));
    }

    public function testKeyOnlyUnscopedServiceFallsBackToRoleFilteredCatalog(): void
    {
        $this->bootMinimalLaravel();
        $this->establishKeyOnlySession([['service_id' => 1], ['service_id' => 3]]);

        // Per-service opt-out of scoping: the legacy instance-wide catalog,
        // which for an app-role session is still the ROLE-filtered catalog.
        $names = $this->resolveNames(['scope_tools' => false]);

        $this->assertSame(['mysql', 'files'], $names);
    }

    public function testLayeredSysadminSessionCannotEscapeExposure(): void
    {
        $this->bootMinimalLaravel();
        // Key + layered admin JWT: user.is_sys_admin with no user-app-role
        // rows (has_role=false is what df-core caches for such admins).
        $this->establishKeyOnlySession([['service_id' => 1]]);
        \DreamFactory\Core\Utility\Session::setUserInfo([
            'id' => 9, 'name' => 'admin', 'email' => 'admin@example.com', 'is_sys_admin' => true,
        ]);
        \Session::put('has_role', false);

        $names = $this->resolveNames(['exposed_services' => ['postgres']]);

        // isSysAdmin() lifts the role filter (full catalog), but the
        // exposed_services filter still applies.
        $this->assertSame(['postgres'], $names);
    }

    public function testExposureCannotGrantBeyondTheAppRole(): void
    {
        $this->bootMinimalLaravel();
        // A role with zero service-access rows denies everything; listing a
        // service in exposed_services must not re-grant it.
        $this->establishKeyOnlySession([]);

        $this->assertSame([], $this->resolveNames(['exposed_services' => ['mysql']]));
    }

    // ---------------------------------------------------------------
    // Minimal Laravel container (vendor tree only)
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
                'Functional role+exposure composition needs the DreamFactory vendor tree '
                . '(Illuminate container + df-core Session); run inside a DF app checkout.'
            );
        }

        $container = new \Illuminate\Container\Container();
        \Illuminate\Container\Container::setInstance($container);
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);

        $container->instance('config', new \Illuminate\Config\Repository([
            'mcp' => ['scope_tools' => true], // config/mcp.php default (MCP_SCOPE_TOOLS)
        ]));
        $container->instance('session', new \Illuminate\Session\Store(
            'testing',
            new \Illuminate\Session\ArraySessionHandler(120)
        ));
        $container->instance('log', new class {
            public function __call(string $method, array $args): void
            {
                // absorb Log::info/warning from AvailableServices
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

        if (!class_exists('Session', false)) {
            class_alias(\Illuminate\Support\Facades\Session::class, 'Session');
        }
    }

    /**
     * The effect of McpStreamController's key path — setApiKey() +
     * setSessionData($appId, null) — using the same df-core calls
     * setSessionData() makes, with the DB-cached app/role records inlined.
     *
     * @param array<int, array<string, mixed>> $roleServiceAccess role.services rows
     */
    private function establishKeyOnlySession(array $roleServiceAccess): void
    {
        \DreamFactory\Core\Utility\Session::setApiKey(str_repeat('a', 64));
        \DreamFactory\Core\Utility\Session::setUserInfo(null); // no user for key-only auth
        \Session::put('app.id', 42);
        \DreamFactory\Core\Utility\Session::setRoleInfo([
            'id' => 5,
            'name' => 'mcp-app-role',
            'role_service_access_by_role_id' => $roleServiceAccess,
        ]);
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
