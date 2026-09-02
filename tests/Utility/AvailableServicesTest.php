<?php

namespace DreamFactory\Core\McpServer\Tests\Utility;

use DreamFactory\Core\McpServer\Utility\AvailableServices;
use PHPUnit\Framework\TestCase;

/**
 * tools/list catalog scoping (issue #48). Pure filter — no Laravel.
 *
 * Unscoped: every DB/file service the session can see (MCP_SCOPE_TOOLS=false
 * and an empty list). Scoped (default): only `exposed_services`; empty = none.
 */
class AvailableServicesTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $catalog;

    protected function setUp(): void
    {
        $this->catalog = [
            ['id' => 1, 'name' => 'mysql', 'type' => 'mysql', 'category' => 'database'],
            ['id' => 2, 'name' => 'postgres', 'type' => 'pgsql', 'category' => 'database'],
            ['id' => 3, 'name' => 'files', 'type' => 'local_file', 'category' => 'file'],
            ['id' => 4, 'name' => 'storefront', 'type' => 'mysql', 'category' => 'database'],
        ];
    }

    public function testUnscopedReturnsTheFullCatalog(): void
    {
        $result = AvailableServices::scope($this->catalog, [], false);

        $this->assertSame(['mysql', 'postgres', 'files', 'storefront'], $this->names($result));
    }

    public function testExposedServicesRestrictsTheCatalog(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['exposed_services' => ['mysql']],
            false
        );

        $this->assertSame(['mysql'], $this->names($result));
    }

    public function testConnectedMcpNameIsNotTreatedAsABackend(): void
    {
        // Service names are unique instance-wide, so the MCP service cannot
        // also be a database. Scoping without exposed_services is an empty catalog.
        $result = AvailableServices::scope(
            $this->catalog,
            ['exposed_services' => ['files']],
            false
        );

        $this->assertSame(['files'], $this->names($result));
    }

    public function testScopeToolsTrueWithEmptyExposedYieldsEmptyCatalog(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['scope_tools' => true],
            false
        );

        $this->assertSame([], $result);
    }

    public function testScopeToolsTrueWithNoMatchingBackendYieldsEmpty(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['scope_tools' => true],
            false
        );

        $this->assertSame([], $result);
    }

    public function testInstanceDefaultScopesWhenPerServiceFlagIsUnset(): void
    {
        $result = AvailableServices::scope($this->catalog, [], true);

        $this->assertSame([], $result);
    }

    public function testExplicitFalseKeepsTheCatalogEvenWhenInstanceDefaultIsOn(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['scope_tools' => false],
            true
        );

        $this->assertSame(['mysql', 'postgres', 'files', 'storefront'], $this->names($result));
    }

    public function testExplicitFalseStillHonorsAnExposedServicesList(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['scope_tools' => false, 'exposed_services' => ['postgres']],
            true
        );

        $this->assertSame(['postgres'], $this->names($result));
    }

    public function testNameMatchIsCaseInsensitive(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['exposed_services' => ['MYSQL']],
            false
        );

        $this->assertSame(['mysql'], $this->names($result));
    }

    public function testCommaSeparatedExposedServicesString(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['exposed_services' => 'mysql, files'],
            false
        );

        $this->assertSame(['mysql', 'files'], $this->names($result));
    }

    public function testJsonArrayExposedServicesString(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['exposed_services' => '["postgres","files"]'],
            false
        );

        $this->assertSame(['postgres', 'files'], $this->names($result));
    }

    public function testObjectEntriesInExposedServices(): void
    {
        $result = AvailableServices::scope(
            $this->catalog,
            ['exposed_services' => [['name' => 'mysql'], ['name' => 'nope']]],
            false
        );

        $this->assertSame(['mysql'], $this->names($result));
    }

    public function testEmptyExposedServicesWithScopingOnIsNone(): void
    {
        $result = AvailableServices::scope($this->catalog, ['scope_tools' => true], false);

        $this->assertSame([], $result);
    }

    public function testNamesHelper(): void
    {
        $this->assertSame([], AvailableServices::names(null));
        $this->assertSame([], AvailableServices::names(''));
        $this->assertSame(['a', 'b'], AvailableServices::names('a, b'));
        $this->assertSame(['a', 'b'], AvailableServices::names(['a', 'b', '']));
    }

    public function testBoolOrNullHelper(): void
    {
        $this->assertNull(AvailableServices::boolOrNull(null));
        $this->assertNull(AvailableServices::boolOrNull(''));
        $this->assertTrue(AvailableServices::boolOrNull(true));
        $this->assertTrue(AvailableServices::boolOrNull('true'));
        $this->assertTrue(AvailableServices::boolOrNull('1'));
        $this->assertFalse(AvailableServices::boolOrNull(false));
        $this->assertFalse(AvailableServices::boolOrNull('false'));
        $this->assertFalse(AvailableServices::boolOrNull(0));
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @return string[]
     */
    private function names(array $services): array
    {
        return array_values(array_map(static fn ($s) => (string) $s['name'], $services));
    }
}
