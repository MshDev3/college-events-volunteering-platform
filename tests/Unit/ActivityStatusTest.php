<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ActivityStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ActivityStatusTest extends TestCase
{
    private DateTimeImmutable $start;
    private DateTimeImmutable $end;

    protected function setUp(): void
    {
        $this->start = new DateTimeImmutable('2026-09-25 10:00');
        $this->end = new DateTimeImmutable('2026-09-25 14:00');
    }

    public function testUpcomingBeforeStart(): void
    {
        self::assertSame(ActivityStatus::UPCOMING, ActivityStatus::resolve($this->start, $this->end, null, new DateTimeImmutable('2026-09-25 09:59')));
    }

    public function testOngoingBetweenStartAndEndInclusive(): void
    {
        self::assertSame(ActivityStatus::ONGOING, ActivityStatus::resolve($this->start, $this->end, null, $this->start));
        self::assertSame(ActivityStatus::ONGOING, ActivityStatus::resolve($this->start, $this->end, null, $this->end));
    }

    public function testCompletedAfterEnd(): void
    {
        self::assertSame(ActivityStatus::COMPLETED, ActivityStatus::resolve($this->start, $this->end, null, new DateTimeImmutable('2026-09-25 14:01')));
    }

    public function testCancelledOverridesEverything(): void
    {
        $cancelled = new DateTimeImmutable('2026-09-20');
        foreach (['2026-09-24', '2026-09-25 12:00', '2026-09-30'] as $now) {
            self::assertSame(ActivityStatus::CANCELLED, ActivityStatus::resolve($this->start, $this->end, $cancelled, new DateTimeImmutable($now)));
        }
    }

    public function testOnlyUpcomingAcceptsRegistrations(): void
    {
        self::assertTrue(ActivityStatus::UPCOMING->acceptsRegistrations());
        self::assertFalse(ActivityStatus::ONGOING->acceptsRegistrations());
        self::assertFalse(ActivityStatus::COMPLETED->acceptsRegistrations());
        self::assertFalse(ActivityStatus::CANCELLED->acceptsRegistrations());
    }

    public function testSqlExpressionChecksCancellationFirst(): void
    {
        $sql = ActivityStatus::sql('e');
        self::assertLessThan(strpos($sql, 'UPCOMING'), strpos($sql, 'cancelled_at IS NOT NULL'));
    }
}
