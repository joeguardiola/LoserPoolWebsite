<?php

namespace LoserPool\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use LoserPool\Pool\Reminders;
use LoserPool\Pool\Standings;
use PHPUnit\Framework\TestCase;

/*
 * The reminder rule: when to send, and to whom.
 *
 * Both halves are calendar arithmetic, which is the part of this project with
 * the worst record. A day-of-year guard inverted in January, and a set of
 * season tests answered against the wall clock and went red on their own. So
 * the window is asserted at named instants rather than trusted.
 */
final class RemindersTest extends TestCase
{
    private function chicago(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('America/Chicago'));
    }

    public function testTheWindowIsTheDayBeforeTheLock(): void
    {
        /* Saturday 26 September 2026: picks lock at midnight into Sunday. */
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-09-26 00:30:00')));
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-09-26 09:00:00')));
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-09-26 23:58:00')));
    }

    public function testNothingSendsOutsideTheLastTwentyFourHours(): void
    {
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-22 10:00:00')), 'Tuesday');
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-24 20:00:00')), 'Thursday');
        $this->assertFalse(
            Reminders::isSendWindow($this->chicago('2026-09-25 20:00:00')),
            'Friday evening is still 28 hours out'
        );
    }

    /* Once the lock falls there is nothing to remind anyone about. */
    public function testNothingSendsOnceThePicksAreLocked(): void
    {
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-27 00:01:00')), 'Sunday');
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-28 10:00:00')), 'Monday');
    }

    /*
     * The reason the window is computed in the pool's timezone rather than a
     * fixed UTC hour in the cron.
     *
     * America/Chicago leaves daylight saving on 1 November 2026, moving from
     * UTC-5 to UTC-6. A cron pinned to a UTC hour silently shifts an hour
     * relative to the deadline for the back half of the season; asked in local
     * time, the same Saturday hour is inside the window on both sides of it.
     */
    public function testTheWindowSurvivesTheEndOfDaylightSaving(): void
    {
        /* Saturday 7 November 2026: CST, UTC-6. */
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-11-07 09:00:00')));
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-11-06 20:00:00')), 'Friday');

        /* The same instants expressed in UTC must answer identically. */
        $utc = new DateTimeImmutable('2026-11-07 15:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(Reminders::isSendWindow($utc), '09:00 CST given as UTC');
    }

    /** @return array<string,array{status:string,outWeek:?int,correct:int}> */
    private function standings(array $statuses): array
    {
        $rows = [];
        foreach ($statuses as $name => $status) {
            $rows[$name] = [
                'status' => $status,
                'outWeek' => $status === Standings::IN ? null : 1,
                'correct' => 0,
            ];
        }
        return $rows;
    }

    public function testOnlyPlayersStillInAndWithoutAPickAreDue(): void
    {
        $standings = $this->standings([
            'alice' => Standings::IN,
            'bob' => Standings::IN,
            'carol' => Standings::OUT,
        ]);
        $picks = ['bob' => [2 => 'Chicago Bears'], 'carol' => []];

        $this->assertSame(['alice'], Reminders::due($standings, $picks, 2));
    }

    /*
     * An eliminated player is never chased. The pick form refuses them, so a
     * reminder would be an instruction to do something the site forbids.
     */
    public function testAnEliminatedPlayerIsNeverReminded(): void
    {
        $standings = $this->standings(['carol' => Standings::OUT]);

        $this->assertSame([], Reminders::due($standings, [], 2));
    }

    /*
     * The job runs hourly, so this is what stops it mailing the same player
     * twelve times on a Saturday.
     */
    public function testAlreadyRemindedPlayersAreSkipped(): void
    {
        $standings = $this->standings(['alice' => Standings::IN, 'bob' => Standings::IN]);

        $this->assertSame(['bob'], Reminders::due($standings, [], 2, ['alice']));
    }

    /* Usernames are case-insensitive everywhere else, and here too. */
    public function testTheSentListIgnoresCase(): void
    {
        $standings = $this->standings(['Alice' => Standings::IN]);

        $this->assertSame([], Reminders::due($standings, [], 2, ['alice']));
    }
}
