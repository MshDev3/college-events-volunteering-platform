<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Paginator;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    private function paginator(): Paginator
    {
        return new Paginator(
            [['id' => 1, 'title' => 'A', 'created_by' => 7], ['id' => 2, 'title' => 'B', 'created_by' => null]],
            12,
            2,
            5,
        );
    }

    public function testToArrayKeepsEverythingByDefault(): void
    {
        $array = $this->paginator()->toArray();

        self::assertSame([['id' => 1, 'title' => 'A', 'created_by' => 7], ['id' => 2, 'title' => 'B', 'created_by' => null]], $array['items']);
        self::assertSame(['total' => 12, 'page' => 2, 'limit' => 5, 'last_page' => 3], $array['meta']);
    }

    /** S9: a public JSON listing can leave internal columns out, including when their value is null. */
    public function testToArrayCanHideItemKeys(): void
    {
        $array = $this->paginator()->toArray(['created_by', 'does_not_exist']);

        self::assertSame([['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']], $array['items']);
        self::assertSame(12, $array['meta']['total'], 'the metadata is unchanged');
    }
}
