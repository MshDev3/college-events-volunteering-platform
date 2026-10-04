<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Small reference lists used by forms and filters (types, categories, departments). */
final class LookupRepository
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    private const TABLES = ['event_types', 'volunteer_categories', 'feedback_categories', 'departments'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(string $table): array
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException("Unknown lookup [$table].");
        }
        $where = $table === 'departments' ? ' WHERE is_active = 1' : '';

        return $this->cache[$table] ??= $this->db->fetchAll("SELECT * FROM `$table`$where ORDER BY id");
    }

    /** id => localized name, for <select> options. @return array<int, string> */
    public function options(string $table): array
    {
        $options = [];
        foreach ($this->all($table) as $row) {
            $options[(int) $row['id']] = localized($row, 'name');
        }

        return $options;
    }

    /** Comma-separated ids for an "in:" validation rule. */
    public function idList(string $table): string
    {
        return implode(',', array_keys($this->options($table)));
    }
}
