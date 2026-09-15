<?php

namespace LoserPool\Pool;

/*
 * Who is still in.
 *
 * A player is out the first time a pick fails: the team they picked won, or
 * tied. Weeks that have not finished do not count either way.
 *
 * Not picking at all is also a failure. It has to be stated separately because
 * it is invisible to the obvious loop: iterating a player's picks only ever
 * visits weeks they actually played, so a missed week was skipped over and
 * someone who never picked at all survived the whole season. Four players had
 * no week 1 pick in 2026 and the table counted every one of them as still in.
 *
 * Which weeks a missed pick counts against is passed in rather than inferred
 * here, because this class deliberately knows nothing about schedules. Two
 * conditions matter and both are the caller's to judge:
 *
 *   - the week has finished, so there was something to get wrong; and
 *   - a pick was actually possible in it. 2026 week 18 is played entirely on
 *     Saturday, and blocked kickoff days make all 32 teams unpickable, so
 *     nobody can submit a week 18 pick through the site. Counting that as a
 *     missed pick would eliminate the entire pool in one go.
 *
 * Week one is special, because the pool allows buying back in after it. A
 * week-one failure therefore only eliminates a player who did not buy back --
 * whether they picked a winner or never picked at all -- and that is not
 * something the picks can reveal, since an eliminated player can still submit
 * picks. Buy-backs are recorded explicitly and passed in.
 */
final class Standings
{
    public const IN = 'in';
    public const OUT = 'out';

    /**
     * @param array<string,array<int,string>> $allPicks username => (week => team)
     * @param callable $checkPick fn(int $week, string $team): int, a Rules::PICK_* value
     * @param string[] $buybacks usernames who bought back in after week one
     * @param string[] $allUsernames everyone registered, including those who never picked
     * @param int[] $weeksRequiringAPick weeks that have finished and in which a
     *        pick was possible; not picking in one of these eliminates
     *
     * @return array<string,array{status:string,outWeek:?int,correct:int}>
     */
    public static function build(
        array $allPicks,
        callable $checkPick,
        array $buybacks = [],
        array $allUsernames = [],
        array $weeksRequiringAPick = []
    ): array {
        $usernames = array_unique(array_merge(array_keys($allPicks), $allUsernames));
        sort($usernames, SORT_NATURAL | SORT_FLAG_CASE);

        $boughtBack = array_flip(array_map('strtolower', $buybacks));

        $required = array_map('intval', $weeksRequiringAPick);

        $standings = [];
        foreach ($usernames as $username) {
            $picks = $allPicks[$username] ?? [];
            ksort($picks);

            $correct = 0;
            $outWeek = null;

            /*
             * Walk the weeks, not the picks. A missed week has no entry in
             * $picks, so iterating the picks alone can never see it -- which is
             * exactly how not picking went unpunished.
             */
            $weeks = array_unique(array_merge(array_map('intval', array_keys($picks)), $required));
            sort($weeks);

            foreach ($weeks as $week) {
                /*
                 * No pick for this week. It can only be a week the caller named
                 * as requiring one -- a week the player did play is in $picks by
                 * definition -- so there is nothing further to test here: an
                 * unfinished week, or one nobody could pick in, is simply never
                 * in the list and so never reaches this loop.
                 */
                if (!array_key_exists($week, $picks)) {
                    if ($week === 1 && isset($boughtBack[strtolower($username)])) {
                        continue;
                    }

                    $outWeek = $week;
                    break;
                }

                $result = $checkPick($week, (string) $picks[$week]);

                if ($result === Rules::PICK_CORRECT) {
                    $correct++;
                    continue;
                }

                if ($result !== Rules::PICK_INCORRECT) {
                    continue; /* not played yet, or no data */
                }

                /* Week one only ends a season for players who did not buy back. */
                if ($week === 1 && isset($boughtBack[strtolower($username)])) {
                    continue;
                }

                $outWeek = $week;
                break;
            }

            $standings[$username] = [
                'status' => $outWeek === null ? self::IN : self::OUT,
                'outWeek' => $outWeek,
                'correct' => $correct,
            ];
        }

        return $standings;
    }

    /** @param array<string,array{status:string,outWeek:?int,correct:int}> $standings */
    public static function stillIn(array $standings): int
    {
        return count(array_filter($standings, static fn($row) => $row['status'] === self::IN));
    }
}
