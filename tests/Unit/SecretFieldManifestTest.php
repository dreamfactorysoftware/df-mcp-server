<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Support\SecretFieldManifest;
use PHPUnit\Framework\TestCase;

/**
 * The secret field manifest is built from a config model's $encrypted / $protected lists and
 * its schema field types. Fake models stand in for DreamFactory's, so no app or DB is needed.
 */
class SecretFieldManifestTest extends TestCase
{
    public function testSecretFieldsComeFromModelListsAndSchemaTypes(): void
    {
        $entry = SecretFieldManifest::forHandler(FakeWarehouseConfig::class, FakeWarehouseConfig::getConfigSchema());

        // username is encrypted but stays readable; passcode/key come from the schema types.
        $this->assertSame(['key', 'passcode', 'password'], $entry['secret']);
        $this->assertSame(['options'], $entry['maps']);
    }

    public function testChildModelUsesItsOwnEffectiveLists(): void
    {
        $entry = SecretFieldManifest::forHandler(FakeChildConfig::class, FakeChildConfig::getConfigSchema());

        // $encrypted is overridden (token); $protected is inherited (password).
        $this->assertSame(['key', 'passcode', 'password', 'token'], $entry['secret']);
    }

    public function testBuildSkipsTypesWithoutHandlersOrSecretsAndSurvivesSchemaErrors(): void
    {
        $manifest = SecretFieldManifest::build([
            $this->type('warehouse', FakeWarehouseConfig::class),
            $this->type('plain', FakePlainConfig::class),
            $this->type('no_handler', null),
            $this->type('missing_class', 'DreamFactory\\Nope\\MissingConfig'),
            $this->type('broken_schema', FakeBrokenSchemaConfig::class),
        ]);

        $this->assertSame(['broken_schema', 'warehouse'], array_keys($manifest));
        $this->assertSame(['secret' => ['secret'], 'maps' => []], $manifest['broken_schema']);
    }

    private function type(string $name, ?string $handler): object
    {
        return new class ($name, $handler) {
            public function __construct(private string $name, private ?string $handler)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getConfigHandler(): ?string
            {
                return $this->handler;
            }
        };
    }
}

class FakeWarehouseConfig
{
    protected $encrypted = ['username', 'password'];
    protected $protected = ['password'];

    public static function getConfigSchema(): array
    {
        return [
            ['name' => 'host', 'type' => 'string'],
            ['name' => 'username', 'type' => 'string'],
            ['name' => 'password', 'type' => 'text'],
            ['name' => 'passcode', 'type' => 'password'],
            ['name' => 'key', 'type' => 'file_certificate_api'],
            ['name' => 'options', 'type' => 'object'],
            ['label' => 'no name'],
        ];
    }
}

class FakeChildConfig extends FakeWarehouseConfig
{
    protected $encrypted = ['token'];
}

class FakePlainConfig
{
    public static function getConfigSchema(): array
    {
        return [['name' => 'base_url', 'type' => 'string']];
    }
}

class FakeBrokenSchemaConfig
{
    protected $encrypted = ['secret'];

    public static function getConfigSchema(): array
    {
        throw new \RuntimeException('table missing');
    }
}
