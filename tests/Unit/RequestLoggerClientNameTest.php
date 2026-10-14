<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Utility\RequestLogger;
use PHPUnit\Framework\TestCase;

/**
 * mcp_request_log.client_name was always NULL: resolveClientName() read a
 * `client_name` attribute off McpOAuthClient, but dynamic client registration
 * stores that value in the `name` column. The dashboard's "MCP clients" panel
 * therefore showed "unknown client" for every OAuth agent, and the
 * X-Mcp-Client-Name hint forwarded to the daemon was empty for OAuth callers.
 */
class RequestLoggerClientNameTest extends TestCase
{
    public function testLoggerReadsTheColumnTheModelActuallyHas(): void
    {
        $logger = file_get_contents(__DIR__ . '/../../src/Utility/RequestLogger.php');
        $model = file_get_contents(__DIR__ . '/../../src/Models/McpOAuthClient.php');

        $this->assertStringContainsString("'name',", $model, 'McpOAuthClient stores the registered client_name in `name`');
        $this->assertStringNotContainsString("'client_name',", $model);
        $this->assertStringContainsString('return $client?->name;', $logger, 'resolveClientName reads `name`');
        $this->assertStringNotContainsString('$client?->client_name', $logger);
    }

    public function testNonOauthCallersAreLabelledByTheirApp(): void
    {
        $logger = file_get_contents(__DIR__ . '/../../src/Utility/RequestLogger.php');
        $daemon = file_get_contents(__DIR__ . '/../../src/Client/McpDaemonClient.php');

        // Same fallback the proxy already uses for X-Mcp-Client-Name: OAuth client name, else the app name.
        $this->assertStringContainsString("self::resolveClientName(\$token) ?? self::resolveAppName(Session::get('app.id'))", $logger);
        $this->assertStringContainsString('App::find($appId)?->name', $logger);
        $this->assertStringContainsString("App::find(\$appId)?->name", $daemon);
    }

    public function testResolveClientNameReturnsTheRegisteredName(): void
    {
        if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
            $this->markTestSkipped('resolveClientName queries Eloquent — needs the DreamFactory vendor tree.');
        }
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }

        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('mcp_oauth_client', function ($t) {
            $t->increments('id');
            $t->string('client_id');
            $t->string('client_secret');
            $t->string('name')->nullable();
            $t->text('redirect_uris')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $capsule->getConnection()->table('mcp_oauth_client')->insert([
            'client_id'     => 'c3a6e5f3ede8954a0f9fe7881f14e1a5',
            'client_secret' => 'secret',
            'name'          => 'Claude',
            'redirect_uris' => '[]',
            'is_active'     => 1,
        ]);

        $token = new McpOAuthAccessToken();
        $token->client_id = 'c3a6e5f3ede8954a0f9fe7881f14e1a5';
        $this->assertSame('Claude', RequestLogger::resolveClientName($token));

        $unknown = new McpOAuthAccessToken();
        $unknown->client_id = 'nope';
        $this->assertNull(RequestLogger::resolveClientName($unknown));
        $this->assertNull(RequestLogger::resolveClientName(null));
    }
}
