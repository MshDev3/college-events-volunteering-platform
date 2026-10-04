<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Database;

/**
 * `php bin/console db:check` — reports whether the runtime account (DB_USERNAME) is least-privilege:
 * only SELECT/INSERT/UPDATE/DELETE on the application's database, nothing server-wide.
 */
final class DbPrivilegeChecker
{
    private const ALLOWED = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param callable(string): void $out @return int exit code (0 = least privilege) */
    public function run(callable $out): int
    {
        $out('Connected as ' . $this->db->value('SELECT CURRENT_USER()') . ' to ' . $this->db->value('SELECT DATABASE()'));
        $grants = array_map(static fn (array $row): string => (string) reset($row), $this->db->fetchAll('SHOW GRANTS FOR CURRENT_USER()'));
        $problems = self::excessPrivileges($grants);
        foreach ($problems as $problem) {
            $out('  ✘ ' . $problem);
        }
        $out($problems === []
            ? 'OK: the application account has data access only (SELECT, INSERT, UPDATE, DELETE).'
            : 'WARNING: the application account has more privileges than it needs. See database/setup/create_app_user.sql.');

        return $problems === [] ? 0 : 1;
    }

    /**
     * @param list<string> $grants lines from SHOW GRANTS
     * @return list<string> human-readable problems (empty = least privilege)
     */
    public static function excessPrivileges(array $grants): array
    {
        $problems = [];
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT (.+?) ON (\S+) TO /i', $grant, $m) !== 1) {
                $problems[] = 'Unrecognised grant: ' . preg_replace('/IDENTIFIED BY .*/i', 'IDENTIFIED BY …', $grant);
                continue;
            }
            [, $privileges, $target] = $m;
            if (stripos($grant, 'WITH GRANT OPTION') !== false) {
                $problems[] = "Can grant privileges to others on $target";
            }
            if (strcasecmp(trim($privileges), 'USAGE') === 0) {
                continue; // "no privileges" placeholder row
            }
            if (str_starts_with(strtoupper($privileges), 'PROXY')) {
                $problems[] = 'Has a PROXY grant';
                continue;
            }
            if ($target === '*.*') {
                $problems[] = "Server-wide privileges: $privileges";
                continue;
            }
            $extra = array_diff(array_map(static fn (string $p): string => strtoupper(trim(preg_replace('/\s*\(.*\)$/', '', $p) ?? $p)), explode(',', $privileges)), self::ALLOWED);
            if ($extra !== []) {
                $problems[] = 'More than data access on ' . $target . ': ' . implode(', ', $extra);
            }
        }

        return $problems;
    }
}
