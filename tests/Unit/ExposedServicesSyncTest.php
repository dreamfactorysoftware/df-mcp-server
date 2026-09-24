<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Support\ExposedServicesSync;
use PHPUnit\Framework\TestCase;

/**
 * Pure list transforms behind the service rename/delete listeners.
 *
 * A backend rename must follow the service into every MCP endpoint's
 * exposed_services list (verified live: the stale old name silently drops
 * the backend from tools/list), and a delete must remove the name so a
 * later service recreated under it cannot silently inherit the exposure
 * (name-squatting). No Laravel — safe standalone.
 */
class ExposedServicesSyncTest extends TestCase
{
    public function testRenameRewritesTheOldNameInPlace(): void
    {
        $this->assertSame(
            ['mysql2', 'files'],
            ExposedServicesSync::renameIn(['mysql', 'files'], 'mysql', 'mysql2')
        );
    }

    public function testRenameMatchesCaseInsensitively(): void
    {
        $this->assertSame(
            ['warehouse', 'files'],
            ExposedServicesSync::renameIn(['MySQL', 'files'], 'mysql', 'warehouse')
        );
    }

    public function testRenameAppliesTheNewSpellingOnCaseOnlyRenames(): void
    {
        $this->assertSame(
            ['mysql'],
            ExposedServicesSync::renameIn(['MySQL'], 'MySQL', 'mysql')
        );
    }

    public function testRenameReturnsNullWhenTheOldNameIsAbsent(): void
    {
        $this->assertNull(
            ExposedServicesSync::renameIn(['postgres', 'files'], 'mysql', 'mysql2'),
            'untouched rows must not be rewritten (null = skip the write)'
        );
    }

    public function testRenameDedupesWhenTheNewNameWasAlreadyListed(): void
    {
        $this->assertSame(
            ['postgres', 'files'],
            ExposedServicesSync::renameIn(['mysql', 'postgres', 'files'], 'mysql', 'postgres')
        );
    }

    public function testRemoveDropsEveryCaseInsensitiveMatch(): void
    {
        $this->assertSame(
            ['files'],
            ExposedServicesSync::removeFrom(['MySQL', 'files', 'mysql'], 'mysql')
        );
    }

    public function testRemoveReturnsNullWhenTheNameIsAbsent(): void
    {
        $this->assertNull(
            ExposedServicesSync::removeFrom(['postgres', 'files'], 'mysql'),
            'untouched rows must not be rewritten (null = skip the write)'
        );
    }

    public function testRemovingTheLastNameYieldsAnEmptyListNotAFallback(): void
    {
        $this->assertSame(
            [],
            ExposedServicesSync::removeFrom(['mysql'], 'mysql'),
            'empty means "expose nothing" — never the instance-wide catalog'
        );
    }

    public function testRemoveReindexesTheSurvivors(): void
    {
        $result = ExposedServicesSync::removeFrom(['a', 'mysql', 'b'], 'mysql');

        $this->assertSame(['a', 'b'], $result);
        $this->assertSame([0, 1], array_keys($result), 'list must stay a JSON array, not an object');
    }
}
