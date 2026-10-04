<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

/**
 * Shared WHERE-clause builders for list filters. Every value is added as a bound parameter;
 * column names are always supplied by the calling repository, never by the request.
 */
trait FilterClauses
{
    /**
     * `?from=YYYY-MM-DD&to=YYYY-MM-DD` (inclusive days). Invalid dates are ignored.
     * For time ranges pass different columns: rows overlapping [from, to] match when
     * $fromColumn is the row's end and $toColumn the row's start.
     *
     * @param list<string> $where
     * @param list<mixed> $params
     * @param array<string, mixed> $filters
     */
    protected function addDateRange(array &$where, array &$params, array $filters, string $fromColumn, string $toColumn): void
    {
        if (self::isIsoDate($filters['from'] ?? null)) {
            $where[] = "$fromColumn >= ?";
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (self::isIsoDate($filters['to'] ?? null)) {
            $where[] = "$toColumn <= ?";
            $params[] = $filters['to'] . ' 23:59:59';
        }
    }

    /**
     * Case-insensitive "contains" search across several columns (LIKE wildcards in the term are escaped).
     *
     * @param list<string> $where
     * @param list<mixed> $params
     * @param list<string> $columns
     */
    protected function addSearch(array &$where, array &$params, mixed $term, array $columns): void
    {
        $term = is_scalar($term) ? trim((string) $term) : '';
        if ($term === '') {
            return;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';
        $where[] = '(' . implode(' OR ', array_map(static fn (string $c): string => "$c LIKE ?", $columns)) . ')';
        foreach ($columns as $_) {
            $params[] = $like;
        }
    }

    private static function isIsoDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
