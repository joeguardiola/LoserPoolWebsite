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

    public function testTheWindowIsTheAfternoonAndEveningBeforeTheLock(): void
    {
        /* Saturday 26 September 2026: picks lock at midnight into Sunday. */
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-09-26 12:00:00')));
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-09-26 18:00:00')));
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-09-26 23:58:00')));
    }

    /*
     * The point of twelve rather than twenty-four: the first run inside the
     * window is the one that sends, so the boundary is the hour the mail
     * actually arrives. Nobody is woken at midnight or six in the morning.
     */
    public function testNothingSendsOvernightOrOnSaturdayMorning(): void
    {
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-26 00:30:00')), 'midnight');
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-26 06:00:00')), 'dawn');
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-26 11:59:00')), 'a minute early');
    }

    public function testNothingSendsOutsideTheLastTwentyFourHours(): void
    {
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-22 10:00:00')), 'Tuesday');
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-09-24 20:00:00')), 'Thursday');
        $this->assertFalse(
            Reminders::isSendWindow($this->chicago('2026-09-25 20:00:00')),
            'Friday evening is still 28 hours out'
        );
        $this->assertFalse(
            Reminders::isSendWindow($this->chicago('2026-09-26 09:00:00')),
            'Saturday morning is still more than twelve hours out'
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
        $this->assertTrue(Reminders::isSendWindow($this->chicago('2026-11-07 13:00:00')));
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-11-07 09:00:00')), 'still morning');
        $this->assertFalse(Reminders::isSendWindow($this->chicago('2026-11-06 20:00:00')), 'Friday');

        /*
         * The same instant expressed in UTC must answer identically. In CST,
         * 13:00 local is 19:00 UTC -- an hour later in UTC than the same local
         * time in September, which is exactly the drift a UTC-pinned cron
         * would have suffered.
         */
        $utc = new DateTimeImmutable('2026-11-07 19:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(Reminders::isSendWindow($utc), '13:00 CST given as UTC');
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

    public function testTheCommissionerSummaryListsWhoWasMailed(): void
    {
        [$subject, $body] = Reminders::summary(2, ['alice', 'bob'], []);

        $this->assertSame('Loser Pool week 2: reminded 2', $subject);
        $this->assertStringContainsString("Reminded (2):\n  alice\n  bob\n", $body);
        $this->assertStringNotContainsString('Could not', $body);
    }

    public function testTheCommissionerSummaryNamesFailures(): void
    {
        [$subject, $body] = Reminders::summary(2, ['alice'], ['carol']);

        $this->assertSame('Loser Pool week 2: reminded 1, 1 failed', $subject);
        $this->assertStringContainsString("Could not be reminded (1):\n  carol\n", $body);
    }
}
