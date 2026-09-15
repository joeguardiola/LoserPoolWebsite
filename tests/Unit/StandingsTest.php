<?php

namespace LoserPool\Tests\Unit;

use LoserPool\Pool\Rules;
use LoserPool\Pool\Standings;
use PHPUnit\Framework\TestCase;

/*
 * Who is still in.
 *
 * This matters beyond curiosity: the pool's endgame rule -- the final four or
 * fewer may split the pot -- cannot be applied if nobody can count who is left.
 */
final class StandingsTest extends TestCase
{
    /** Results keyed "week:team", anything absent counts as not yet played. */
    private function checker(array $results): callable
    {
        return static function (int $week, string $team) use ($results): int {
            return $results[$week . ':' . $team] ?? Rules::PICK_UNDECIDED;
        };
    }

    /*
     * Not picking is a failure, and it was not being counted.
     *
     * The loop used to walk a player's picks, which only ever visits weeks they
     * actually played -- so a missed week was skipped and someone who never
     * picked at all survived the season. In 2026 four players had no week 1
     * pick and the table counted every one of them as still in.
     */
    public function testNotPickingInAFinishedWeekEliminates(): void
    {
        $standings = Standings::build(
            ['joeg' => [2 => 'Giants']],
            $this->checker(['2:Giants' => Rules::PICK_CORRECT]),
            [],
            ['joeg'],
            [1, 2]
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(1, $standings['joeg']['outWeek']);
    }

    /* A player who never picked at all is out, not quietly carried all season. */
    public function testAPlayerWhoNeverPickedIsOut(): void
    {
        $standings = Standings::build(
            [],
            $this->checker([]),
            [],
            ['ghost'],
            [1]
        );

        $this->assertSame(Standings::OUT, $standings['ghost']['status']);
        $this->assertSame(1, $standings['ghost']['outWeek']);
    }

    /* A week nobody has played yet cannot be missed. */
    public function testNotPickingInAnUnfinishedWeekDoesNotEliminate(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears']],
            $this->checker(['1:Bears' => Rules::PICK_CORRECT]),
            [],
            ['joeg'],
            [1]
        );

        $this->assertSame(Standings::IN, $standings['joeg']['status']);
    }

    /*
     * The week 18 guard, and the reason the eliminating weeks are passed in.
     *
     * 2026 week 18 is played entirely on Saturday, which is a blocked kickoff
     * day, so all 32 teams are unpickable and nobody can submit a week 18 pick
     * through the site. If a week in which no pick was possible counted as a
     * missed pick, the first render after it finished would eliminate every
     * remaining player at once. The caller leaves such a week out of the list.
     */
    public function testAWeekNobodyCouldPickInDoesNotEliminate(): void
    {
        /* A survivor who picked, and won, every week the site let them pick. */
        $picks = [];
        $results = [];
        for ($week = 1; $week <= 17; $week++) {
            $picks[$week] = 'Team' . $week;
            $results[$week . ':Team' . $week] = Rules::PICK_CORRECT;
        }

        $standings = Standings::build(
            ['joeg' => $picks],
            $this->checker($results),
            [],
            ['joeg'],
            range(1, 17) /* week 18 finished too, but no pick was possible in it */
        );

        $this->assertSame(Standings::IN, $standings['joeg']['status']);
        $this->assertNull($standings['joeg']['outWeek']);
        $this->assertSame(17, $standings['joeg']['correct']);
    }

    /* Buying back in forgives a missed week 1 exactly as it forgives a bad one. */
    public function testABuybackForgivesAMissedWeekOne(): void
    {
        $standings = Standings::build(
            ['joeg' => [2 => 'Giants']],
            $this->checker(['2:Giants' => Rules::PICK_CORRECT]),
            ['joeg'],
            ['joeg'],
            [1, 2]
        );

        $this->assertSame(Standings::IN, $standings['joeg']['status']);
    }

    /* A missed week 2 is not forgiven by a week 1 buy-back. */
    public function testABuybackDoesNotForgiveALaterMissedWeek(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears']],
            $this->checker(['1:Bears' => Rules::PICK_CORRECT]),
            ['joeg'],
            ['joeg'],
            [1, 2]
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(2, $standings['joeg']['outWeek']);
    }

    /* The earliest failure is the one that counts, missed or wrong. */
    public function testAMissedWeekBeatsALaterBadPick(): void
    {
        $standings = Standings::build(
            ['joeg' => [3 => 'Jets']],
            $this->checker(['3:Jets' => Rules::PICK_INCORRECT]),
            [],
            ['joeg'],
            [2, 3]
        );

        $this->assertSame(2, $standings['joeg']['outWeek']);
    }

    public function testAPlayerWhoseTeamsAllLostIsStillIn(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants']],
            $this->checker(['1:Bears' => Rules::PICK_CORRECT, '2:Giants' => Rules::PICK_CORRECT])
        );

        $this->assertSame(Standings::IN, $standings['joeg']['status']);
        $this->assertNull($standings['joeg']['outWeek']);
        $this->assertSame(2, $standings['joeg']['correct']);
    }

    public function testAFailedPickEliminates(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants', 3 => 'Jets']],
            $this->checker([
                '1:Bears' => Rules::PICK_CORRECT,
                '2:Giants' => Rules::PICK_INCORRECT,
                '3:Jets' => Rules::PICK_CORRECT,
            ])
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(2, $standings['joeg']['outWeek'], 'the week they went out, not the last one played');
    }

    /*
     * Elimination is dated to the first failure, not the most recent one.
     *
     * Nothing stops an eliminated player from carrying on submitting picks, so
     * a player can accumulate several failures. Reporting the last one would
     * date their exit to whenever they lost interest rather than to the week
     * they actually went out.
     */
    public function testEliminationIsDatedToTheFirstFailureNotTheLast(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants', 3 => 'Jets', 4 => 'Rams']],
            $this->checker([
                '1:Bears' => Rules::PICK_CORRECT,
                '2:Giants' => Rules::PICK_INCORRECT,
                '3:Jets' => Rules::PICK_INCORRECT,
                '4:Rams' => Rules::PICK_INCORRECT,
            ])
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(2, $standings['joeg']['outWeek']);
    }

    /* Wins after elimination do not count towards anything. */
    public function testPicksAfterEliminationDoNotChangeTheOutcome(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants', 3 => 'Jets']],
            $this->checker([
                '1:Bears' => Rules::PICK_INCORRECT,
                '2:Giants' => Rules::PICK_CORRECT,
                '3:Jets' => Rules::PICK_CORRECT,
            ])
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(1, $standings['joeg']['outWeek']);
        $this->assertSame(0, $standings['joeg']['correct'], 'nothing after elimination is counted');
    }

    /* Unplayed weeks decide nothing either way. */
    public function testUndecidedWeeksDoNotEliminate(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants']],
            $this->checker(['1:Bears' => Rules::PICK_CORRECT])
        );

        $this->assertSame(Standings::IN, $standings['joeg']['status']);
    }

    /*
     * Week one is buy-back-able, so losing it only ends the season for players
     * who did not buy back. That cannot be inferred from the picks: an
     * eliminated player can keep submitting them.
     */
    public function testAWeekOneLossEliminatesWithoutABuyback(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears']],
            $this->checker(['1:Bears' => Rules::PICK_INCORRECT])
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
    }

    public function testABuybackSurvivesAWeekOneLoss(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants']],
            $this->checker([
                '1:Bears' => Rules::PICK_INCORRECT,
                '2:Giants' => Rules::PICK_CORRECT,
            ]),
            ['joeg']
        );

        $this->assertSame(Standings::IN, $standings['joeg']['status']);
    }

    /* Buying back covers week one only, not the rest of the season. */
    public function testABuybackDoesNotForgiveLaterWeeks(): void
    {
        $standings = Standings::build(
            ['joeg' => [1 => 'Bears', 2 => 'Giants']],
            $this->checker([
                '1:Bears' => Rules::PICK_INCORRECT,
                '2:Giants' => Rules::PICK_INCORRECT,
            ]),
            ['joeg']
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(2, $standings['joeg']['outWeek']);
    }

    public function testBuybacksMatchRegardlessOfCase(): void
    {
        $standings = Standings::build(
            ['JoeG' => [1 => 'Bears']],
            $this->checker(['1:Bears' => Rules::PICK_INCORRECT]),
            ['joeg']
        );

        $this->assertSame(Standings::IN, $standings['JoeG']['status']);
    }

    /* Registered players who never picked have not lost anything yet. */
    public function testRegisteredPlayersWithNoPicksAreIncludedAndStillIn(): void
    {
        $standings = Standings::build([], $this->checker([]), [], ['newcomer']);

        $this->assertArrayHasKey('newcomer', $standings);
        $this->assertSame(Standings::IN, $standings['newcomer']['status']);
    }

    public function testCountsWhoIsLeft(): void
    {
        $standings = Standings::build(
            [
                'alive1' => [1 => 'Bears'],
                'alive2' => [1 => 'Jets'],
                'goner' => [1 => 'Giants'],
            ],
            $this->checker([
                '1:Bears' => Rules::PICK_CORRECT,
                '1:Jets' => Rules::PICK_CORRECT,
                '1:Giants' => Rules::PICK_INCORRECT,
            ])
        );

        $this->assertSame(2, Standings::stillIn($standings));
    }

    /*
     * A tie eliminates, and Rules already reports one as an incorrect pick --
     * so standings need no special case, but it is worth pinning down.
     */
    public function testATiedPickEliminatesLikeAnyOtherFailure(): void
    {
        $standings = Standings::build(
            ['joeg' => [3 => 'Bears']],
            $this->checker(['3:Bears' => Rules::PICK_INCORRECT])
        );

        $this->assertSame(Standings::OUT, $standings['joeg']['status']);
        $this->assertSame(3, $standings['joeg']['outWeek']);
    }
}
