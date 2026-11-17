<?php

namespace DreamFactory\Core\McpServer\Tests\Unit;

use DreamFactory\Core\McpServer\Models\McpOAuthAccessToken;
use DreamFactory\Core\McpServer\Models\McpOAuthClient;
use DreamFactory\Core\McpServer\Utility\RequestLogger;
use PHPUnit\Framework\TestCase;

/**
 * Every MCP service has one shared OAuth client row. /register stored the
 * caller's client_name only when it created that row, so after one client
 * registered as "Client A" and another as "Client B" on the same service, the
 * request log (and the X-Mcp-Client-Name hint to the daemon) attributed both
 * clients' calls to "Client A". Each registration's name is now recorded
 * against the redirect URIs it registered and carried onto the access token
 * issued for a code sent to that URI.
 */
class OAuthClientNameAttributionTest extends TestCase
{
    private const A_URI = 'http://127.0.0.1:41001/cb';
    private const B_URI = 'http://localhost:52002/callback';
    private const CLIENT_ID = 'c3a6e5f3ede8954a0f9fe7881f14e1a5';

    private ?object $container = null;

    protected function tearDown(): void
    {
        if ($this->container !== null) {
            \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
            $this->container = null;
        }
    }

    private function needsEloquent(): void
    {
        if (!class_exists(\Illuminate\Database\Eloquent\Model::class)) {
            $this->markTestSkipped('McpOAuthClient is an Eloquent model — needs the DreamFactory vendor tree.');
        }
    }

    /** Register A, then B, on the same shared row, the way register() does. */
    private function registerAThenB(): McpOAuthClient
    {
        $afterA = McpOAuthClient::registrableRedirectUris([self::A_URI], [], []);
        $names = McpOAuthClient::withRegisteredName([], [self::A_URI], $afterA, 'Client A');

        $afterB = McpOAuthClient::registrableRedirectUris([self::B_URI], $afterA, []);
        $names = McpOAuthClient::withRegisteredName($names, [self::B_URI], $afterB, 'Client B');

        $client = new McpOAuthClient();
        $client->name = 'Client A';
        $client->redirect_uris = $afterB;
        $client->client_names = $names;

        return $client;
    }

    public function testEachRegistrationKeepsItsOwnName(): void
    {
        $this->needsEloquent();
        $client = $this->registerAThenB();

        $this->assertSame('Client A', $client->registeredNameFor(self::A_URI));
        $this->assertSame('Client B', $client->registeredNameFor(self::B_URI));
        $this->assertSame('Client A', $client->name, 'the shared row keeps the first registrant as its fallback name');
    }

    public function testReRegistrationRenamesOnlyItsOwnUris(): void
    {
        $this->needsEloquent();
        $client = $this->registerAThenB();

        $client->client_names = McpOAuthClient::withRegisteredName(
            $client->client_names,
            [self::A_URI],
            McpOAuthClient::registrableRedirectUris([self::A_URI], $client->redirect_uris, []),
            'Client A v2'
        );

        $this->assertSame('Client A v2', $client->registeredNameFor(self::A_URI));
        $this->assertSame('Client B', $client->registeredNameFor(self::B_URI));
    }

    public function testOnlyRegisteredUrisCarryAName(): void
    {
        $this->needsEloquent();
        $foreign = 'https://evil.example/cb';
        $registered = McpOAuthClient::registrableRedirectUris([self::A_URI, $foreign], [], []);

        $names = McpOAuthClient::withRegisteredName(
            ['https://pruned.example/cb' => 'Old'],
            [self::A_URI, $foreign],
            $registered,
            'Client A'
        );

        $this->assertSame([self::A_URI => 'Client A'], $names, 'dropped and pruned URIs get no name');

        $client = new McpOAuthClient();
        $client->client_names = $names;
        $this->assertNull($client->registeredNameFor($foreign));

        $neverRegistered = new McpOAuthClient();
        $this->assertNull($neverRegistered->registeredNameFor(self::A_URI), 'no names: callers fall back to the row name');
    }

    public function testNewLoopbackPortResolvesToTheLatestMatchingRegistration(): void
    {
        $this->needsEloquent();
        $client = $this->registerAThenB();
        $cUri = 'http://127.0.0.1:41003/cb';
        $client->client_names = McpOAuthClient::withRegisteredName(
            $client->client_names,
            [$cUri],
            McpOAuthClient::registrableRedirectUris([$cUri], $client->redirect_uris, []),
            'Client C'
        );

        // Exact match wins even though C registered later on the same path.
        $this->assertSame('Client A', $client->registeredNameFor(self::A_URI));
        // A native app back on a new ephemeral port: the latest registration that accepts it.
        $this->assertSame('Client C', $client->registeredNameFor('http://127.0.0.1:49999/cb'));
        $this->assertSame('Client B', $client->registeredNameFor('http://localhost:60000/callback'));
        $this->assertNull($client->registeredNameFor('https://other.example/cb'));

        // A registering again moves it to the end, so it now wins the loopback match.
        $client->client_names = McpOAuthClient::withRegisteredName($client->client_names, [self::A_URI], $client->redirect_uris, 'Client A');
        $this->assertSame('Client A', $client->registeredNameFor('http://127.0.0.1:49999/cb'));
    }

    public function testClientNameIsNormalizedBeforeItIsStored(): void
    {
        $this->needsEloquent();

        $this->assertSame('Cursor', McpOAuthClient::normalizeClientName('  Cursor  '));
        $this->assertNull(McpOAuthClient::normalizeClientName(''));
        $this->assertNull(McpOAuthClient::normalizeClientName('   '));
        $this->assertNull(McpOAuthClient::normalizeClientName(null));
        $this->assertNull(McpOAuthClient::normalizeClientName(['Cursor']));
        $this->assertSame(200, mb_strlen(McpOAuthClient::normalizeClientName(str_repeat('é', 300))));
    }

    public function testTokensAreLoggedUnderTheClientThatObtainedThem(): void
    {
        $this->needsEloquent();
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }
        $this->bootSqliteWithMigrations();

        // A registers first and creates the shared row, B registers second.
        $afterA = McpOAuthClient::registrableRedirectUris([self::A_URI], [], []);
        McpOAuthClient::create([
            'client_id' => self::CLIENT_ID,
            'client_secret' => 'secret',
            'name' => 'Client A',
            'redirect_uris' => $afterA,
            'client_names' => McpOAuthClient::withRegisteredName([], [self::A_URI], $afterA, 'Client A'),
            'is_active' => true,
        ]);
        $legacy = $this->issueToken(null); // a token issued before the per-token name existed

        $client = McpOAuthClient::findByClientId(self::CLIENT_ID);
        $afterB = McpOAuthClient::registrableRedirectUris([self::B_URI], $client->redirect_uris ?? [], []);
        $client->update([
            'redirect_uris' => $afterB,
            'client_names' => McpOAuthClient::withRegisteredName($client->client_names ?? [], [self::B_URI], $afterB, 'Client B'),
        ]);

        // The token endpoint names each token for its code's redirect_uri.
        $tokenA = $this->issueToken(McpOAuthClient::findByClientId(self::CLIENT_ID)?->registeredNameFor(self::A_URI));
        $tokenB = $this->issueToken(McpOAuthClient::findByClientId(self::CLIENT_ID)?->registeredNameFor(self::B_URI));

        $this->assertSame('Client A', RequestLogger::resolveClientName(McpOAuthAccessToken::findValidAccessToken($tokenA->access_token)));
        $this->assertSame('Client B', RequestLogger::resolveClientName(McpOAuthAccessToken::findValidAccessToken($tokenB->access_token)));
        $this->assertSame('Client A', RequestLogger::resolveClientName(McpOAuthAccessToken::findValidAccessToken($legacy->access_token)), 'old tokens fall back to the row name');

        // A refresh keeps the token row, so it keeps its client.
        $tokenB->refresh();
        $this->assertSame('Client B', RequestLogger::resolveClientName(McpOAuthAccessToken::findValidAccessToken($tokenB->access_token)));
    }

    public function testRegisterAndTokenEndpointRecordThePerRegistrationName(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Http/Controllers/McpOAuthController.php');

        $register = $this->between($src, 'public function register(', 'public function authorizeGet(');
        $this->assertStringContainsString("McpOAuthClient::normalizeClientName(\$request->input('client_name'))", $register);
        $this->assertStringContainsString('McpOAuthClient::withRegisteredName($client->client_names ?? [], $requestedRedirectUris, $allowedUris, $clientName)', $register);
        $this->assertSame(2, substr_count($register, "'client_names' => \$clientNames"), 'both the update and the create path store the names');

        $grant = $this->between($src, 'private function handleAuthorizationCodeGrant(', 'private function handleRefreshTokenGrant(');
        $this->assertStringContainsString(
            "'client_name' => McpOAuthClient::findByClientId(\$authCode->client_id)?->registeredNameFor(\$authCode->redirect_uri)",
            $grant
        );

        $logger = file_get_contents(__DIR__ . '/../../src/Utility/RequestLogger.php');
        $resolve = substr($logger, strpos($logger, 'public static function resolveClientName'));
        $this->assertLessThan(
            strpos($resolve, 'McpOAuthClient::where('),
            strpos($resolve, 'return $token->client_name;'),
            'the token\'s own client name wins over the shared row name'
        );
    }

    private function issueToken(?string $clientName): McpOAuthAccessToken
    {
        return McpOAuthAccessToken::createToken([
            'client_id' => self::CLIENT_ID,
            'client_name' => $clientName,
            'user_id' => 1,
            'df_session_token' => 'jwt',
            'user_email' => 'admin@example.com',
        ]);
    }

    /** In-memory sqlite behind Eloquent and the Schema facade, with this package's real OAuth migrations. */
    private function bootSqliteWithMigrations(): void
    {
        $container = new \Illuminate\Container\Container();
        $capsule = new \Illuminate\Database\Capsule\Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->bootEloquent();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $this->container = $container;

        $dir = __DIR__ . '/../../database/migrations/';
        (require $dir . '2025_12_05_000000_create_mcp_oauth_tables.php')->up();
        $migration = require $dir . '2026_11_17_000000_add_client_names_to_mcp_oauth_tables.php';
        $migration->up();
        $migration->up(); // idempotent, like its siblings

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $this->assertTrue($schema->hasColumn('mcp_oauth_client', 'client_names'));
        $this->assertTrue($schema->hasColumn('mcp_oauth_access_token', 'client_name'));
    }

    private function between(string $src, string $start, string $end): string
    {
        $from = strpos($src, $start);
        $this->assertNotFalse($from, "{$start} not found");
        $to = strpos($src, $end, $from);
        $this->assertNotFalse($to, "{$end} not found");

        return substr($src, $from, $to - $from);
    }
}
