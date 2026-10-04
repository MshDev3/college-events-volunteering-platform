<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\Migrator;
use App\Core\Container;
use App\Core\Database;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;

/**
 * Service-level tests against a real, disposable MariaDB database (DB_TEST_DATABASE).
 *
 * The test database is dropped and migrated once per PHPUnit run, and the container's Database
 * is pointed at it before any service is resolved. Each test starts from empty business tables
 * (reference data from migration 002 is kept). It refuses to run against DB_DATABASE.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $migrated = false;

    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        $name = (string) config('db.test_database');
        if ($name === '' || $name === config('db.database')) {
            self::markTestSkipped('DB_TEST_DATABASE must be set and differ from DB_DATABASE.');
        }
        if (!self::$migrated) {
            (new Migrator((array) config('db'), dirname(__DIR__, 2) . '/database/migrations', (string) config('app.timezone')))
                ->migrate($name, true, static function (): void {
                });
            app(Container::class)->instance(
                Database::class,
                Database::connect((array) config('db'), $name, (string) config('app.timezone')),
            );
            self::$migrated = true;
        }
    }

    protected function setUp(): void
    {
        $this->db = app(Database::class);
        self::assertSame(config('db.test_database'), $this->db->value('SELECT DATABASE()'), 'Integration tests must run on the test database.');
        foreach (['mail_queue', 'email_change_requests', 'notifications', 'audit_logs', 'rate_limits', 'password_reset_tokens', 'auth_remember_tokens', 'feedback',
            'contact_messages', 'facility_reservations', 'volunteer_registrations', 'volunteer_opportunities',
            'event_registrations', 'events', 'users'] as $table) {
            $this->db->query("DELETE FROM `$table`");
        }
    }

    /**
     * Replace the mail driver with an in-memory one and return the list it fills.
     * Cached services are dropped so they are rebuilt with it.
     * @return \ArrayObject<int, \App\Core\Mail\MailMessage>
     */
    protected function captureMail(): \ArrayObject
    {
        $sent = new \ArrayObject();
        $container = app(Container::class);
        $instances = new \ReflectionProperty(Container::class, 'instances');
        $instances->setValue($container, array_filter(
            $instances->getValue($container),
            static fn (string $id): bool => !str_starts_with($id, 'App\\Services\\') && !str_starts_with($id, 'App\\Http\\'),
            ARRAY_FILTER_USE_KEY,
        ));
        $container->instance(\App\Core\Mail\Mailer::class, new class ($sent) implements \App\Core\Mail\Mailer {
            public function __construct(private readonly \ArrayObject $sent)
            {
            }

            public function send(\App\Core\Mail\MailMessage $message): void
            {
                $this->sent->append($message);
            }
        });

        return $sent;
    }

    protected function user(Role $role = Role::STUDENT, string $password = 'Passw0rd!x'): int
    {
        static $n = 0;
        $n++;

        return $this->db->insert('users', [
            'full_name' => "Test User $n",
            'student_id' => $role === Role::STUDENT ? (string) (500000000 + $n) : null,
            'email' => "user$n." . bin2hex(random_bytes(3)) . '@test.local',
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'role_id' => $role->id(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function event(string $start = '+2 days', string $end = '+2 days +2 hours', int $capacity = 10, array $overrides = []): int
    {
        return $this->db->insert('events', $overrides + [
            'title_ar' => 'فعالية', 'title_en' => 'Event', 'description_ar' => 'x', 'description_en' => 'x',
            'event_type_id' => (int) $this->db->value("SELECT id FROM event_types WHERE code = 'other'"),
            'location_ar' => 'قاعة', 'location_en' => 'Hall',
            'start_datetime' => date('Y-m-d H:i:s', strtotime($start)),
            'end_datetime' => date('Y-m-d H:i:s', strtotime($end)),
            'capacity' => $capacity,
        ]);
    }

    protected function opportunity(string $start = '+2 days', string $end = '+2 days +3 hours', float $hours = 3, int $capacity = 10): int
    {
        return $this->db->insert('volunteer_opportunities', [
            'category_id' => (int) $this->db->value("SELECT id FROM volunteer_categories WHERE code = 'design'"),
            'title_ar' => 'تطوع', 'title_en' => 'Volunteering', 'description_ar' => 'x', 'description_en' => 'x',
            'location_ar' => 'x', 'location_en' => 'x',
            'start_datetime' => date('Y-m-d H:i:s', strtotime($start)),
            'end_datetime' => date('Y-m-d H:i:s', strtotime($end)),
            'volunteer_hours' => $hours, 'capacity' => $capacity,
        ]);
    }

    /** Assert that $fn throws a BusinessRuleException with the given translation key. */
    protected function assertRule(string $key, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected business rule [$key].");
        } catch (\App\Core\Exceptions\BusinessRuleException $e) {
            self::assertSame($key, $e->key);
        }
    }
}
