<?php

namespace LoserPool\Pool;

use LoserPool\Nfl\Schedule;

/*
 * The pool's rules, as pure functions over a Schedule.
 *
 * Nothing here touches the network, the database, the clock, or the session.
 * Given the same Schedule it always returns the same answer, which is what
 * makes the rules testable against real recorded ESPN payloads.
 */
final class Rules
{
    /* check() outcomes, from the pool's perspective: you PICK a team to lose. */
    public const PICK_CORRECT = 1;    // the team lost -- good pick
    public const PICK_INCORRECT = -1; // the team won -- eliminated
    public const PICK_UNDECIDED = 0;  // not finished, tied, or unknown

    /*
     * Teams a player may not pick this week:
     *   - teams on a bye (no game at all), and
     *   - teams whose game kicks off before the pick deadline.
     *
     * The second rule is expressed as a list of weekday abbreviations rather
     * than a comparison against the deadline, because the 2026 opener is a
     * Wednesday game and a naive "no Thursday games" check misses it.
     *
     * The list now includes Saturday, which makes 2026 week 18 -- a
     * Saturday-only slate -- unpickable for all 32 teams. See
     * SeasonConfig::BLOCKED_KICKOFF_DAYS: that is a decision, not an oversight.
     *
     * A schedule we could not load blocks nothing. Silently blocking every
     * team would look identical to a bye week and would quietly break picking;
     * an unrestricted dropdown is the safer failure.
     *
     * @param string[] $allTeams
     * @param string[] $blockedKickoffDays e.g. ['Wed', 'Thu', 'Fri']
     * @return string[]
     */
    public static function ineligibleTeams(Schedule $schedule, array $allTeams, array $blockedKickoffDays): array
    {
        if ($schedule->isEmpty()) {
            return [];
        }

        $playing = $schedule->teamsPlaying();
        $ineligible = array_values(array_diff($allTeams, $playing));

        foreach ($schedule->games() as $game) {
            if (in_array($game->kickoffDay(), $blockedKickoffDays, true)) {
                foreach ($game->teams() as $team) {
                    $ineligible[] = $team;
                }
            }
        }

        $ineligible = array_values(array_unique($ineligible));
        sort($ineligible);
        return $ineligible;
    }

    /*
     * Why each unavailable team is unavailable, as a team => reason map.
     *
     * ineligibleTeams() answers whether a pick is allowed. This answers what to
     * tell the player, which is a different question: a team missing from the
     * list is indistinguishable from a team that never existed, and someone
     * hunting for a team they cannot find has no way to know whether it is on a
     * bye, playing Thursday, or one they already used.
     *
     * @param string[] $allTeams
     * @param string[] $blockedKickoffDays
     * @return array<string,string> team => human readable reason
     */
    public static function unavailabilityReasons(Schedule $schedule, array $allTeams, array $blockedKickoffDays): array
    {
        if ($schedule->isEmpty()) {
            return [];
        }

        $reasons = [];

        foreach (array_diff($allTeams, $schedule->teamsPlaying()) as $team) {
            $reasons[$team] = 'Bye week';
        }

        foreach ($schedule->games() as $game) {
            $day = $game->kickoffDay();
            if (!in_array($day, $blockedKickoffDays, true)) {
                continue;
            }
            foreach ($game->teams() as $team) {
                /* Naming the day is more useful than "plays too early". */
                $reasons[$team] = 'Plays ' . self::dayName($day);
            }
        }

        return $reasons;
    }

    private static function dayName(string $abbreviation): string
    {
        $names = [
            'Mon' => 'Monday',
            'Tue' => 'Tuesday',
            'Wed' => 'Wednesday',
            'Thu' => 'Thursday',
            'Fri' => 'Friday',
            'Sat' => 'Saturday',
            'Sun' => 'Sunday',
        ];

        return $names[$abbreviation] ?? $abbreviation;
    }

    /*
     * Did this pick come in? Returns one of the PICK_* constants.
     *
     * A tie eliminates the player. You are picking a team to lose, and a team
     * that ties did not lose -- so the pick failed. This is not the same as
     * "undecided": the game is over and the answer is no.
     *
     * It needs stating because ESPN encodes a tie as `winner: false` on both
     * competitors, so the naive read of "was this team the winner?" quietly
     * scores a tie as a successful pick.
     */
    public static function checkPick(Schedule $schedule, string $team): int
    {
        $game = $schedule->gameFor($team);
        if ($game === null || !$game->isCompleted()) {
            return self::PICK_UNDECIDED;
        }

        if ($game->isTie()) {
            return self::PICK_INCORRECT;
        }

        return $game->winner() === $team ? self::PICK_INCORRECT : self::PICK_CORRECT;
    }
}
