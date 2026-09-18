<?php

namespace LoserPool\Pool;

use DateTimeImmutable;

/*
 * Who still needs to pick, and whether it is time to tell them.
 *
 * Pure, and separate from the job that sends: both halves are calendar
 * arithmetic, and calendar arithmetic in this project has gone wrong twice in
 * ways nothing noticed until the date arrived. Here they can be tested at any
 * hour of any week.
 */
final class Reminders
{
    /*
     * Picks are due 11:59pm Central Saturday and the lock falls at 00:00
     * Sunday, so the window opens 24 hours before that lock -- midnight
     * Friday into Saturday -- and closes when the lock does.
     *
     * Expressed against the lock rather than a stored hour because the
     * deadline is the lock; a published time a minute earlier is a courtesy,
     * and duplicating it here would make two answers possible.
     */
    public const HOURS_BEFORE_LOCK = 24;

    /*
     * Is $now inside the send window for the current week?
     *
     * Computed in the pool's timezone rather than UTC, which is the whole
     * point of putting it here: America/Chicago is UTC-5 in September and
     * UTC-6 from November, so a cron fixed in UTC drifts an hour mid-season
     * and would start mailing people at 11pm Thursday in the back half of the
     * schedule.
     */
    public static function isSendWindow(DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone(SeasonConfig::timezone());

        /*
         * No day-of-week guard. One was here, and mutation testing showed the
         * suite could not tell whether it existed: the distance to the next
         * lock already answers every day. A second expression of the same rule
         * is one more thing that can disagree with the first.
         */
        $lock = self::lockAfter($local);
        $secondsToLock = $lock->getTimestamp() - $local->getTimestamp();

        return $secondsToLock > 0 && $secondsToLock <= self::HOURS_BEFORE_LOCK * 3600;
    }

    /* Midnight at the end of the coming Saturday, in local time. */
    private static function lockAfter(DateTimeImmutable $local): DateTimeImmutable
    {
        $saturday = (int) $local->format('N') === 6
            ? $local
            : $local->modify('next saturday');

        return $saturday->modify('+1 day')->setTime(0, 0);
    }

    /*
     * Players who should be mailed: still in, no pick for this week, and not
     * already mailed about it.
     *
     * An eliminated player is not reminded. They cannot submit -- the pick
     * form refuses them -- so a reminder would be an instruction to do
     * something impossible.
     *
     * @param array<string,array{status:string,outWeek:?int,correct:int}> $standings
     * @param array<string,array<int,string>> $allPicks username => week => team
     * @param string[] $alreadySent usernames mailed about this week
     * @return string[] usernames, in the order the standings gave them
     */
    public static function due(array $standings, array $allPicks, int $week, array $alreadySent = []): array
    {
        $sent = array_flip(array_map('strtolower', $alreadySent));

        $due = [];
        foreach ($standings as $username => $row) {
            if ($row['status'] !== Standings::IN) {
                continue;
            }

            if (isset($allPicks[$username][$week])) {
                continue;
            }

            if (isset($sent[strtolower($username)])) {
                continue;
            }

            $due[] = $username;
        }

        return $due;
    }
}
