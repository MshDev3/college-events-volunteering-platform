<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Database;

/**
 * Runs database/migrations/NNN_*.sql in order, once each, tracked in `schema_migrations`.
 *
 * Connects with DB_MIGRATE_USERNAME/DB_MIGRATE_PASSWORD when set, so the web application's own
 * account can be limited to SELECT/INSERT/UPDATE/DELETE.
 */
final class Migrator
{
    /** @var array<string, mixed> */
    private readonly array $dbConfig;

    /** @param array<string, mixed> $dbConfig */
    public function __construct(
        array $dbConfig,
        private readonly string $migrationsPath,
        private readonly string $timezone,
    ) {
        $this->dbConfig = self::schemaCredentials($dbConfig);
    }

    /**
     * The connection settings for schema changes: the migration account if configured, else the app account.
     * @param array<string, mixed> $dbConfig @return array<string, mixed>
     */
    public static function schemaCredentials(array $dbConfig): array
    {
        if ((string) ($dbConfig['migrate_username'] ?? '') !== '') {
            $dbConfig['username'] = $dbConfig['migrate_username'];
            $dbConfig['password'] = $dbConfig['migrate_password'] ?? '';
        }

        return $dbConfig;
    }

    /** @param callable(string): void $out */
    public function migrate(string $database, bool $fresh, callable $out): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new \InvalidArgumentException('Invalid database name (letters, digits and _ only).');
        }
        $server = Database::connect($this->dbConfig, '', $this->timezone);
        if ($fresh) {
            $server->pdo()->exec("DROP DATABASE IF EXISTS `$database`");
            $out("Dropped database $database");
        }
        $server->pdo()->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $db = Database::connect($this->dbConfig, $database, $this->timezone);
        $db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');

        $applied = array_column($db->fetchAll('SELECT migration FROM schema_migrations'), 'migration');
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        $ran = 0;
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                $db->pdo()->exec($statement);
            }
            $db->insert('schema_migrations', ['migration' => $name]);
            $out("Migrated: $name");
            $ran++;
        }
        $out($ran === 0 ? 'Nothing to migrate.' : "Done ($ran migration(s)).");
    }

    /**
     * Split an SQL script into statements on ';' outside quotes and comments.
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote === null && $char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $buffer .= "\n";
                continue;
            }
            if ($quote === null && ($char === "'" || $char === '"' || $char === '`')) {
                $quote = $char;
            } elseif ($quote !== null && $char === $quote) {
                if ($next === $quote) { // escaped quote ('')
                    $buffer .= $char . $next;
                    $i++;
                    continue;
                }
                $quote = null;
            } elseif ($quote !== null && $char === '\\') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }

            if ($quote === null && $char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }
}
