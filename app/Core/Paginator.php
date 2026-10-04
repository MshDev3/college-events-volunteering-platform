<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Server-side pagination result.
 * @template T
 */
final class Paginator
{
    public const MAX_PAGE = 100000;

    public readonly int $lastPage;

    /** @param list<T> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    }

    /** Read ?page and ?limit from the request, clamped to configured bounds. @return array{0:int,1:int} [page, limit] */
    public static function params(Request $request, ?int $default = null): array
    {
        $min = (int) config('pagination.min', 5);
        $max = (int) config('pagination.max', 50);
        $default ??= (int) config('pagination.default', 10);

        // Capped: a huge ?page= would overflow the OFFSET arithmetic (and no list here has 100k pages).
        $page = min(self::MAX_PAGE, filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1);
        $limit = filter_var($request->query('limit'), FILTER_VALIDATE_INT) ?: $default;

        return [$page, max($min, min($max, $limit))];
    }

    public static function offset(int $page, int $limit): int
    {
        return ($page - 1) * $limit;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /**
     * @param list<string> $hideItemKeys keys removed from every item (items must then be arrays)
     * @return array{items:list<T>, meta:array{total:int,page:int,limit:int,last_page:int}}
     */
    public function toArray(array $hideItemKeys = []): array
    {
        return [
            'items' => $hideItemKeys === []
                ? $this->items
                : array_map(static fn (array $item): array => array_diff_key($item, array_flip($hideItemKeys)), $this->items),
            'meta' => ['total' => $this->total, 'page' => $this->page, 'limit' => $this->perPage, 'last_page' => $this->lastPage],
        ];
    }
}
