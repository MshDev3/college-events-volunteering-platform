<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Migrator;
use App\Core\Container;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterAndMigratorTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], [], [], []);
    }

    public function testRouteParametersAndIdCasting(): void
    {
        $router = new Router(new Container());
        $router->get('/events/{id}', static fn (Request $r, string $id) => Response::html('event ' . $id));
        $router->get('/reset-password/{token}', static fn (Request $r, string $token) => Response::html($token));

        self::assertSame('event 42', $router->dispatch($this->request('GET', '/events/42'))->body());
        $token = str_repeat('a1', 32);
        self::assertSame($token, $router->dispatch($this->request('GET', '/reset-password/' . $token))->body());
    }

    public function testHasGetRouteOnlyForPages(): void
    {
        $router = new Router(new Container());
        $router->get('/reset-password/{token}', static fn () => Response::html('x'));
        $router->post('/reset-password/{token}', static fn () => Response::html('x'));
        $router->post('/events/{id}/register', static fn () => Response::html('x'));

        self::assertTrue($router->hasGetRoute('/reset-password/' . str_repeat('ab', 32)));
        self::assertFalse($router->hasGetRoute('/events/5/register'), 'POST-only actions fall back to home');
    }

    public function testNotFoundAndMethodNotAllowed(): void
    {
        $router = new Router(new Container());
        $router->get('/events/{id}', static fn () => Response::html('x'));

        foreach ([['GET', '/events/abc', 404], ['GET', '/nope', 404], ['POST', '/events/1', 405]] as [$method, $path, $status]) {
            try {
                $router->dispatch($this->request($method, $path));
                self::fail("$method $path should fail");
            } catch (HttpException $e) {
                self::assertSame($status, $e->status);
            }
        }
    }

    public function testSqlSplitterRespectsQuotesAndComments(): void
    {
        $sql = "-- comment; with semicolon\nINSERT INTO t VALUES ('a;b', 'it''s');\nCREATE TABLE x (id INT); -- trailing\n";
        $statements = Migrator::splitStatements($sql);
        self::assertCount(2, $statements);
        self::assertSame("INSERT INTO t VALUES ('a;b', 'it''s')", $statements[0]);
    }

    public function testMigrationsUseTheSeparateSchemaAccountWhenConfigured(): void
    {
        $db = ['username' => 'tvtc_app', 'password' => 'app-secret', 'migrate_username' => '', 'migrate_password' => ''];
        self::assertSame('tvtc_app', Migrator::schemaCredentials($db)['username'], 'falls back to the app account');

        $creds = Migrator::schemaCredentials(['migrate_username' => 'tvtc_migrate', 'migrate_password' => 'm-secret'] + $db);
        self::assertSame(['tvtc_migrate', 'm-secret'], [$creds['username'], $creds['password']]);
    }

    public function testMigratorRejectsUnsafeDatabaseNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Migrator(['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => ''], __DIR__, 'UTC'))
            ->migrate('x`; DROP DATABASE y; --', false, static function (): void {
            });
    }

    public function testAllMigrationFilesParse(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [] as $file) {
            self::assertNotEmpty(Migrator::splitStatements((string) file_get_contents($file)), basename($file));
        }
    }
}
