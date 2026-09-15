<?php

namespace LoserPool\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use LoserPool\Pool\WeekResolver;
use PHPUnit\Framework\TestCase;

/*
 * Which week is it?
 *
 * The old implementation pinned the site to week 18 for the whole of 2026 via
 * a hardcoded `if ($year == 2026) return 18;`, on top of day-of-year constants
 * tuned to the 2025 calendar. These tests exist so neither can come back.
 */
final class WeekResolverTest extends TestCase
{
    private const SEASON = 2026;
    private const MAX_WEEKS = 18;

    private function chicago(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('America/Chicago'));
    }

    private function weekOneStart(): DateTimeImmutable
    {
        return $this->chicago('2026-09-08 00:00:00');
    }

    private function resolve(?array $current, string $now): int
    {
        return WeekResolver::resolve(
            $current,
            self::SEASON,
            $this->chicago($now),
            $this->weekOneStart(),
            self::MAX_WEEKS
        );
    }

    public function testUsesTheLiveWeekDuringTheRegularSeason(): void
    {
        $current = ['year' => 2026, 'seasonType' => 2, 'week' => 7];

        $this->assertSame(7, $this->resolve($current, '2026-10-20 09:00:00'));
    }

    /* Before kickoff the pool is taking week 1 picks, not week 0. */
    public function testPreseasonResolvesToWeekOne(): void
    {
        $current = ['year' => 2026, 'seasonType' => 1, 'week' => 4];

        $this->assertSame(1, $this->resolve($current, '2026-08-28 09:00:00'));
    }

    public function testPostseasonPinsToTheFinalWeek(): void
    {
        $current = ['year' => 2026, 'seasonType' => 3, 'week' => 1];

        $this->assertSame(18, $this->resolve($current, '2027-01-15 09:00:00'));
    }

    public function testOutOfRangeLiveWeekIsClamped(): void
    {
        $this->assertSame(18, $this->resolve(['year' => 2026, 'seasonType' => 2, 'week' => 23], '2027-01-05 09:00:00'));
        $this->assertSame(1, $this->resolve(['year' => 2026, 'seasonType' => 2, 'week' => 0], '2026-09-10 09:00:00'));
    }

    /*
     * ESPN rolls on to the next season's preseason while the pool is still
     * configured for this one. That must not be read as "we are in week 4".
     */
    public function testADifferentSeasonYearFallsBackToTheCalendar(): void
    {
        $current = ['year' => 2027, 'seasonType' => 1, 'week' => 4];

        $this->assertSame(5, $this->resolve($current, '2026-10-08 09:00:00'));
    }

    public function testFallsBackToTheCalendarWhenEspnIsUnreachable(): void
    {
        $this->assertSame(1, $this->resolve(null, '2026-09-09 12:00:00'));
        $this->assertSame(2, $this->resolve(null, '2026-09-15 12:00:00'));
        $this->assertSame(3, $this->resolve(null, '2026-09-22 12:00:00'));
    }

    public function testCalendarFallbackReturnsWeekOneBeforeTheSeasonStarts(): void
    {
        $this->assertSame(1, $this->resolve(null, '2026-07-01 12:00:00'));
        $this->assertSame(1, $this->resolve(null, '2026-09-07 23:59:00'));
    }

    public function testCalendarFallbackNeverExceedsTheFinalWeek(): void
    {
        $this->assertSame(18, $this->resolve(null, '2027-06-01 12:00:00'));
    }

    /*
     * Regression, and the reason lateLiveWeek() exists.
     *
     * Pool weeks turn over at 00:00 Tuesday. ESPN's scoreboard does not: it
     * goes on reporting the week whose games finished on Monday night until it
     * rolls over on Wednesday. On Tuesday 15 September 2026 -- the first day of
     * pool week 2 -- ESPN still said week 1, so the site called week 1 current,
     * and because Tuesday is outside the Sunday/Monday lock every player could
     * edit a week 1 pick whose games had already been played and settled.
     */
    public function testTuesdayAdvancesPastAnEspnWeekThatHasNotRolledOver(): void
    {
        $current = ['year' => 2026, 'seasonType' => 2, 'week' => 1];

        $this->assertSame(2, $this->resolve($current, '2026-09-15 00:01:00'));
        $this->assertSame(2, $this->resolve($current, '2026-09-15 23:59:00'));
    }

    /*
     * The rest of the pool week, where the two agree. Wednesday onwards ESPN
     * has caught up, and nothing here may nudge the week forward a second time.
     */
    public function testTheLiveWeekIsLeftAloneOnceEspnHasRolledOver(): void
    {
        $current = ['year' => 2026, 'seasonType' => 2, 'week' => 2];

        $this->assertSame(2, $this->resolve($current, '2026-09-16 12:00:00')); /* Wed */
        $this->assertSame(2, $this->resolve($current, '2026-09-19 12:00:00')); /* Sat */
        $this->assertSame(2, $this->resolve($current, '2026-09-20 12:00:00')); /* Sun */
        $this->assertSame(2, $this->resolve($current, '2026-09-21 23:59:00')); /* Mon */
    }

    /*
     * The advance is capped at a single week, so a mis-set WEEK_ONE_START costs
     * one week rather than running the season away to week 18.
     */
    public function testTheCalendarMayOnlyAdvanceTheLiveWeekByOne(): void
    {
        $current = ['year' => 2026, 'seasonType' => 2, 'week' => 2];

        $this->assertSame(3, $this->resolve($current, '2026-11-24 12:00:00'));
    }

    /* A live week ahead of the calendar is the floor and is never rewound. */
    public function testALiveWeekAheadOfTheCalendarIsKept(): void
    {
        $current = ['year' => 2026, 'seasonType' => 2, 'week' => 6];

        $this->assertSame(6, $this->resolve($current, '2026-09-15 12:00:00'));
    }

    /*
     * Regression: the site reported week 18 for every date in 2026 because the
     * year alone decided the answer.
     */
    public function testTheYearAloneDoesNotEndTheSeason(): void
    {
        $inSeason = $this->resolve(['year' => 2026, 'seasonType' => 2, 'week' => 3], '2026-09-24 09:00:00');

        $this->assertSame(3, $inSeason);
        $this->assertNotSame(18, $inSeason);
    }
}
