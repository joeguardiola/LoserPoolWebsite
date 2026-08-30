<?php

namespace LoserPool\Tests\Unit;

use DateTimeZone;
use LoserPool\Pool\SeasonConfig;
use PHPUnit\Framework\TestCase;

/*
 * Guards on the one file that gets edited at season rollover.
 */
final class SeasonConfigTest extends TestCase
{
    /*
     * The table suffix must track the season year.
     *
     * Bumping YEAR while leaving TABLE_SUFFIX behind would silently point the
     * new season at the previous season's tables: every player would appear
     * pre-registered, carrying last year's picks, and the no-repeat rule would
     * reject teams they never picked this year. Nothing would error.
     */
    public function testTableSuffixMatchesTheSeasonYear(): void
    {
        $this->assertSame(
            substr((string) SeasonConfig::YEAR, -2),
            SeasonConfig::TABLE_SUFFIX,
            'TABLE_SUFFIX must be the last two digits of YEAR, or the season reuses old tables.'
        );
    }

    /* Week one must fall inside the configured season, not a leftover year. */
    public function testWeekOneStartsInTheConfiguredSeason(): void
    {
        $this->assertSame(SeasonConfig::YEAR, (int) SeasonConfig::weekOneStart()->format('Y'));
    }

    /* Pool weeks run Tuesday to Monday, so the anchor must be a Tuesday. */
    public function testWeekOneStartsOnATuesday(): void
    {
        $this->assertSame('Tue', SeasonConfig::weekOneStart()->format('D'));
    }

    public function testTimezoneIsValid(): void
    {
        $this->assertInstanceOf(DateTimeZone::class, SeasonConfig::timezone());
        $this->assertSame(SeasonConfig::TIMEZONE, SeasonConfig::timezone()->getName());
    }

    /*
     * Every day before the 11:59 p.m. Saturday deadline is blocked, Saturday
     * included. Blocking Saturday costs the pool week 18, which is played
     * entirely on Saturday in 2026 -- an accepted cost, recorded here and on
     * the constant itself so it is not quietly reverted as a bug fix.
     */
    public function testEveryDayBeforeTheDeadlineIsBlocked(): void
    {
        $this->assertSame(
            ['Wed', 'Thu', 'Fri', 'Sat'],
            SeasonConfig::BLOCKED_KICKOFF_DAYS
        );
    }
}
