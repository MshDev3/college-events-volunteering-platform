<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Console\LegacyImporter;
use App\Console\Migrator;
use App\Core\Database;
use App\Domain\Role;

/**
 * M-5: imported legacy administrators never keep their old (policy-free) passwords.
 * Uses a throw-away "<test database>_legacy" database with a minimal legacy `users` table.
 */
final class LegacyImportTest extends IntegrationTestCase
{
    private Database $server;
    private string $legacyName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacyName = config('db.test_database') . '_legacy';
        $this->server = Database::connect(Migrator::schemaCredentials((array) config('db')), '', (string) config('app.timezone'));
        $this->server->pdo()->exec("DROP DATABASE IF EXISTS `{$this->legacyName}`");
        $this->server->pdo()->exec("CREATE DATABASE `{$this->legacyName}` CHARACTER SET utf8mb4");
        $this->server->pdo()->exec("CREATE TABLE `{$this->legacyName}`.users (id INT PRIMARY KEY, username VARCHAR(100), email VARCHAR(100), password VARCHAR(255), role VARCHAR(20))");
        $this->db->query("DELETE FROM settings WHERE `key` = 'legacy_imported_at'");
    }

    protected function tearDown(): void
    {
        $this->server->pdo()->exec("DROP DATABASE IF EXISTS `{$this->legacyName}`");
        $this->db->query("DELETE FROM settings WHERE `key` = 'legacy_imported_at'");
        parent::tearDown();
    }

    public function testLegacyAdminsMustResetWhileStudentsKeepTheirHash(): void
    {
        $legacyHash = password_hash('legacy123', PASSWORD_BCRYPT, ['cost' => 4]);
        $insert = $this->server->pdo()->prepare("INSERT INTO `{$this->legacyName}`.users VALUES (?, ?, ?, ?, ?)");
        $insert->execute([1, 'Old Trainer', 'Trainer@Legacy.test', $legacyHash, 'trainer']);
        $insert->execute([2, 'Old Trainee', 'trainee@legacy.test', $legacyHash, 'trainee']);
        $insert->execute([3, 'Plain Text', 'plain@legacy.test', 'secret', 'trainee']);

        $lines = [];
        (new LegacyImporter(Database::connect((array) config('db'), $this->legacyName, (string) config('app.timezone')), $this->db))
            ->run(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        $admin = $this->db->fetch('SELECT password_hash, role_id FROM users WHERE email = ?', ['trainer@legacy.test']);
        self::assertSame(Role::ADMIN->id(), (int) $admin['role_id']);
        self::assertStringStartsWith('$argon2id$', (string) $admin['password_hash']);
        self::assertFalse(password_verify('legacy123', (string) $admin['password_hash']), 'the legacy admin password must not work');
        self::assertStringContainsString('ADMIN — legacy password NOT imported', implode("\n", $lines));

        self::assertSame($legacyHash, $this->db->value('SELECT password_hash FROM users WHERE email = ?', ['trainee@legacy.test']), 'student bcrypt hash kept (rehashed at next login)');
        self::assertFalse(password_verify('secret', (string) $this->db->value('SELECT password_hash FROM users WHERE email = ?', ['plain@legacy.test'])));
    }
}
