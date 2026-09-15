<?php

namespace LoserPool\Pool;

use DateTimeImmutable;

/*
 * Works out which week the pool is in.
 *
 * The previous implementation derived this from the day of the year using
 * constants tuned to one specific season, plus a hardcoded `if year == 2026
 * return 18` that pinned the site to a finished season. Both are replaced by
 * asking ESPN, with calendar arithmetic as an offline fallback.
 *
 * ESPN is authoritative about which NFL week is being played, but it is not
 * authoritative about when the *pool's* week turns over. Pool weeks run
 * Tuesday to Monday; ESPN's scoreboard keeps reporting the week whose games
 * just finished until it rolls over on Wednesday. For the Tuesday in between,
 * the two disagree by one, and taking ESPN's answer reopens the week that has
 * already been played. See lateLiveWeek() below.
 *
 * Pure: every input is a parameter, so each branch is directly testable.
 */
final class WeekResolver
{
    public const PRESEASON = 1;
    public const REGULAR_SEASON = 2;

    /**
     * @param array|null $current Result of ScheduleSource::currentSeasonWeek(),
     *                            or null when ESPN could not be reached.
     */
    public static function resolve(
        ?array $current,
        int $seasonYear,
        DateTimeImmutable $now,
        DateTimeImmutable $weekOneStart,
        int $maxWeeks
    ): int {
        if ($current !== null && (int) ($current['year'] ?? 0) === $seasonYear) {
            $type = (int) ($current['seasonType'] ?? 0);

            if ($type === self::PRESEASON) {
                return 1; /* season hasn't started; week 1 is what people pick */
            }
            if ($type === self::REGULAR_SEASON) {
                return self::lateLiveWeek(
                    self::clamp((int) ($current['week'] ?? 1), $maxWeeks),
                    $now,
                    $weekOneStart,
                    $maxWeeks
                );
            }
            return $maxWeeks; /* playoffs or later: the pool is over */
        }

        /*
         * No usable live answer -- either the fetch failed, or ESPN has already
         * rolled on to another season's preseason while we are still configured
         * for this one. Fall back to the calendar.
         */
        return self::fromCalendar($now, $weekOneStart, $maxWeeks);
    }

    /*
     * ESPN's week number, advanced when the pool's calendar has already moved
     * on and ESPN has not.
     *
     * The gap is at most one week and it is always ESPN that is behind, so the
     * calendar may only ever advance the live answer by a single week. That
     * bound is what keeps a mis-set WEEK_ONE_START from running the season
     * away: a wrong constant costs one week, not the rest of the season, and
     * it cannot rewind a week that has been played either, because the live
     * answer is still the floor.
     */
    private static function lateLiveWeek(
        int $liveWeek,
        DateTimeImmutable $now,
        DateTimeImmutable $weekOneStart,
        int $maxWeeks
    ): int {
        $calendarWeek = self::fromCalendar($now, $weekOneStart, $maxWeeks);

        if ($calendarWeek <= $liveWeek) {
            return $liveWeek;
        }

        return self::clamp($liveWeek + 1, $maxWeeks);
    }

    /*
     * Pool weeks run Tuesday to Monday, so $weekOneStart is the Tuesday before
     * the season opener rather than the opener itself.
     */
    private static function fromCalendar(DateTimeImmutable $now, DateTimeImmutable $weekOneStart, int $maxWeeks): int
    {
        if ($now < $weekOneStart) {
            return 1;
        }
        $elapsedDays = (int) $weekOneStart->diff($now)->days;
        return self::clamp(intdiv($elapsedDays, 7) + 1, $maxWeeks);
    }

    private static function clamp(int $week, int $maxWeeks): int
    {
        if ($week < 1) {
            return 1;
        }
        return $week > $maxWeeks ? $maxWeeks : $week;
    }
}
