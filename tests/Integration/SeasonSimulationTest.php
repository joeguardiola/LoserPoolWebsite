<?php

namespace LoserPool\Tests\Integration;

use LoserPool\Nfl\Teams;
use LoserPool\Storage\SqliteStore;
use LoserPool\Tests\AssertsTeamOptions;
use LoserPool\Tests\FakeScheduleSource;
use LoserPool\Tests\SeasonBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Handlers/pick_handler.php';
require_once __DIR__ . '/../../src/Handlers/user_handler.php';
require_once __DIR__ . '/../../src/Handlers/team_handler.php';

use function PickHandling\ph_add_pick;
use function PickHandling\ph_get_picks_html_table;
use function UserHandling\uh_get_user_option_list_html;
use function UserHandling\uh_add_user;
use function TeamHandler\get_team_options_html;

/*
 * Plays a season through the real handlers, week by week.
 *
 * Every other test looks at one rule in isolation. This one asks whether the
 * rules behave when they interact and the calendar moves: players accumulate
 * picks, weeks finish, the field thins, and what the page renders has to keep
 * up. Nothing here is stubbed except the clock, the schedule and the database.
 *
 * It is the closest thing to watching a season happen without waiting for one.
 */
final class SeasonSimulationTest extends TestCase
{
    use AssertsTeamOptions;

    private const PIN = '1234';

    private SqliteStore $store;

    /** @var array<int,\LoserPool\Nfl\Schedule> */
    private array $weeks = [];

    protected function setUp(): void
    {
        $this->store = SqliteStore::open(':memory:', '26');
        lp_store($this->store);
        $this->weeks = [];

        /*
         * Start every test in week 1, before it has said so itself.
         *
         * Most tests here register their players before the first openWeek(),
         * and registration closes after week 1 -- so without this the gate is
         * answered by whatever the *previous* test left behind in the
         * process-wide schedule source, and by the real wall clock. Both were
         * invisible until the season started: lp_registration_is_open() returns
         * true outright while lp_season_in_progress() is false, so all the way
         * up to 8 September 2026 the suite passed without ever consulting
         * either. It went red on its own on the first Tuesday of week 2, with
         * no commit behind it.
         */
        $this->enterWeek(1);
    }

    protected function tearDown(): void
    {
        lp_clock(null, true);
    }

    /* Move to a week, with the schedules of every week so far still known. */
    private function enterWeek(int $week, string $dayOfWeek = 'tuesday'): void
    {
        $when = $dayOfWeek === 'sunday' ? SeasonBuilder::sunday($week) : SeasonBuilder::tuesday($week);
        lp_clock($when->setTime(10, 0));

        lp_schedule_source(new FakeScheduleSource(
            $this->weeks,
            ['year' => 2026, 'seasonType' => 2, 'week' => $week]
        ));
    }

    /* Record how a week finished, then re-enter so results are visible. */
    private function settleWeek(int $week, array $losers, int $nowWeek): void
    {
        $this->weeks[$week] = SeasonBuilder::week($week, $losers, true);
        $this->enterWeek($nowWeek);
    }

    private function openWeek(int $week, array $thursdayTeams = []): void
    {
        $this->weeks[$week] = SeasonBuilder::week($week, [], false, $thursdayTeams);
        $this->enterWeek($week);
    }

    private function register(string $username): void
    {
        uh_add_user('Player', $username . '@example.com', $username, self::PIN, self::PIN);
    }

    private function pick(string $username, string $team): string
    {
        return ph_add_pick($username, $team, self::PIN);
    }

    private function survivors(): int
    {
        $table = ph_get_picks_html_table();
        preg_match('/<strong>(\d+)<\/strong> still in/', $table, $matches);
        return (int) ($matches[1] ?? -1);
    }

    /*
     * Four players, four weeks, the field thinning as picks fail.
     *
     * The point is the interaction: eliminations must follow from results
     * alone, with nobody marking anyone out.
     */
    public function testAFieldThinsOutOverASeasonWithNoIntervention(): void
    {
        $teams = Teams::all();
        [$aTeam, $bTeam, $cTeam, $dTeam] = [$teams[0], $teams[2], $teams[4], $teams[6]];

        foreach (['alice', 'bob', 'carol', 'dave'] as $player) {
            $this->register($player);
        }

        /* Week 1: everyone picks a different team. */
        $this->openWeek(1);
        $this->pick('alice', $aTeam);
        $this->pick('bob', $bTeam);
        $this->pick('carol', $cTeam);
        $this->pick('dave', $dTeam);
        $this->assertSame(4, $this->survivors(), 'nothing has been played yet');

        /* Alice and Bob picked losers, so they survive. Carol and Dave did not. */
        $this->settleWeek(1, [$aTeam, $bTeam], 2);
        $this->assertSame(2, $this->survivors());

        /* Week 2: the two survivors pick again. */
        $this->openWeek(2);
        $this->pick('alice', $teams[8]);
        $this->pick('bob', $teams[10]);
        $this->settleWeek(2, [$teams[8]], 3);
        $this->assertSame(1, $this->survivors(), 'Bob backed a winner and is out');

        /* Week 3: Alice alone, and she keeps going. */
        $this->openWeek(3);
        $this->pick('alice', $teams[12]);
        $this->settleWeek(3, [$teams[12]], 4);
        $this->assertSame(1, $this->survivors());

        $standings = ph_get_picks_html_table();
        $this->assertStringContainsString('Still in', $standings);
        $this->assertStringContainsString('Out &middot; wk 1', $standings, 'carol and dave went out in week 1');
        $this->assertStringContainsString('Out &middot; wk 2', $standings, 'bob went out in week 2');
    }

    /*
     * The username list leads with the players who are still in. Eliminated
     * players stay on it, labelled: removing them reads as a lost
     * registration, and a week-one buy-back is recorded by hand afterwards, so
     * removal would lock a player who has paid to stay in out of picking until
     * the commissioner got round to it.
     */
    public function testTheUsernameListPutsEliminatedPlayersLastAndLabelsThem(): void
    {
        $teams = Teams::all();

        $this->register('alice');
        $this->register('zeb');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->pick('zeb', $teams[2]);
        /* Zeb backed a winner, so he is out despite sorting last already. */
        $this->settleWeek(1, [$teams[0]], 2);

        $options = uh_get_user_option_list_html();

        $this->assertStringContainsString('zeb (out', $options);
        $this->assertStringNotContainsString('alice (out', $options);
        $this->assertLessThan(
            strpos($options, 'zeb'),
            strpos($options, 'alice'),
            'players still in come first'
        );
    }

    /*
     * Picks already sitting in the store from after a player's elimination.
     *
     * The handler now refuses these, but the rendering still has to hold: a
     * season's worth of them was recorded while it did not, and the
     * commissioner's bin/set-pick.php can still write one. Scoring them put a
     * row of green survival ticks next to a status of "Out", which reads as
     * though the player is still alive.
     *
     * Bob's week 2 pick therefore goes in through the store, not the handler.
     * Routing it through ph_add_pick() would now be refused, and the test
     * would be asserting against a table that never had the row in it.
     */
    public function testPicksMadeAfterEliminationAreNotScored(): void
    {
        $teams = Teams::all();

        $this->register('alice');
        $this->register('bob');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->pick('bob', $teams[2]);
        /* Bob backed a winner in week 1 and did not buy back, so he is out. */
        $this->settleWeek(1, [$teams[0]], 2);
        $this->assertSame(1, $this->survivors());

        /* Both have a loser down for week 2. Only Alice's counts. */
        $this->openWeek(2);
        $this->pick('alice', $teams[4]);
        $this->assertStringContainsString('knocked out in week 1', $this->pick('bob', $teams[6]));
        $this->store->savePick('bob', $teams[6], 2);
        $this->settleWeek(2, [$teams[4], $teams[6]], 3);

        $table = ph_get_picks_html_table();

        $this->assertStringContainsString('pick-void', $table, "Bob's week 2 pick is shown but not scored");
        $this->assertSame(2, substr_count($table, 'res-correct'), 'only alice scores in weeks 1 and 2');
        $this->assertStringContainsString($teams[6], $table, 'the pick itself is still listed');
        $this->assertSame(1, $this->survivors(), 'a later pick cannot bring an eliminated player back');
    }

    /* The no-repeat rule has to hold across the whole season, not one week. */
    public function testATeamCannotBeReusedLaterInTheSeason(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[0]], 2);

        $this->openWeek(2);
        $this->assertStringContainsString('Cannot repeat', $this->pick('alice', $teams[0]));

        /* And the dropdown shows it greyed out, naming the week it was used. */
        $this->assertTeamUnavailable(get_team_options_html('alice'), $teams[0], 'Used in week 1');
    }

    /* A tie ends a season, because a team that tied did not lose. */
    public function testATieEliminatesMidSeason(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);

        $this->weeks[1] = SeasonBuilder::weekWithTie(1, $teams[0], $teams[1]);
        $this->enterWeek(2);

        $this->assertSame(0, $this->survivors());
        $this->assertStringContainsString('Out &middot; wk 1', ph_get_picks_html_table());
    }

    /*
     * A week 1 buy-back brings a player back, and only for week 1.
     */
    public function testABuybackRestoresAPlayerForWeekOneOnly(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[1]], 2);
        $this->assertSame(0, $this->survivors(), 'her team won, so she is out');

        $this->store->grantBuyback('alice');
        $this->assertSame(1, $this->survivors(), 'and back in after buying back');

        /* Week 2 is not forgiven. */
        $this->openWeek(2);
        $this->pick('alice', $teams[2]);
        $this->settleWeek(2, [$teams[3]], 3);
        $this->assertSame(0, $this->survivors());
        $this->assertStringContainsString('Out &middot; wk 2', ph_get_picks_html_table());
    }

    /* Results colour in as weeks finish, and only for weeks that finished. */
    public function testResultsAppearOnlyOnceAWeekHasFinished(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);

        /* Before the slate, other players' picks are concealed entirely. */
        $this->assertStringContainsString('Submitted', ph_get_picks_html_table());

        $this->settleWeek(1, [$teams[0]], 2);
        $table = ph_get_picks_html_table();
        $this->assertStringContainsString('res-correct', $table);
        $this->assertStringNotContainsString('res-wrong', $table);

        /* Week 2 is picked but unplayed, so it stays uncoloured. */
        $this->openWeek(2);
        $this->pick('alice', $teams[2]);
        $this->enterWeek(2, 'sunday');
        $table = ph_get_picks_html_table();
        $this->assertSame(1, substr_count($table, 'res-correct'), 'only week 1 has a result');
    }

    /* Picks lock on Sunday, in every week of the season including January. */
    public function testPicksLockOnSundayThroughoutTheSeason(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        foreach ([1, 10, 18] as $index => $week) {
            $this->openWeek($week);
            $this->assertStringContainsString(
                'Pick recorded',
                $this->pick('alice', $teams[$index * 2]),
                "week $week should accept a pick on Tuesday"
            );

            $this->enterWeek($week, 'sunday');
            $this->assertStringContainsString(
                "Picks are locked",
                $this->pick('alice', $teams[$index * 2 + 1]),
                "week $week should be locked on Sunday"
            );
        }
    }

    /* Teams playing before the deadline stay out of the dropdown all season. */
    public function testEarlyKickoffTeamsAreBlockedInAnyWeek(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(7, [$teams[0], $teams[1]]);
        $options = get_team_options_html('alice');

        $this->assertTeamUnavailable($options, $teams[0], 'Plays Thursday');
        $this->assertTeamUnavailable($options, $teams[1], 'Plays Thursday');
        $this->assertTeamSelectable($options, $teams[2]);
    }

    /*
     * The endgame the pot-split rule depends on: the count has to be right
     * when it reaches four.
     */
    public function testTheSurvivorCountIsCorrectWhenTheFieldReachesFour(): void
    {
        $teams = Teams::all();
        /* Three characters minimum: the same rule the registration form states. */
        $players = ['pl1', 'pl2', 'pl3', 'pl4', 'pl5', 'pl6'];
        foreach ($players as $player) {
            $this->register($player);
        }

        $this->openWeek(1);
        foreach ($players as $index => $player) {
            $this->pick($player, $teams[$index * 2]);
        }

        /* The first four picked losers; the last two did not. */
        $this->settleWeek(1, [$teams[0], $teams[2], $teams[4], $teams[6]], 2);

        $this->assertSame(4, $this->survivors());
        $this->assertStringContainsString('<strong>4</strong> still in of 6', ph_get_picks_html_table());
    }

    /*
     * An eliminated player cannot enter another pick.
     *
     * The pool used to accept them and simply not count them, which is why
     * buy-backs are recorded by hand -- continued play proved nothing. Now the
     * handler refuses, so the picks table and the pick form agree about who is
     * still playing.
     */
    public function testAnEliminatedPlayerCannotSubmitAnotherPick(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[1]], 2);
        $this->assertSame(0, $this->survivors(), 'her team won, so she is out');

        $this->openWeek(2);
        $result = $this->pick('alice', $teams[2]);

        $this->assertStringContainsString('knocked out in week 1', $result);
        $this->assertArrayNotHasKey(2, $this->store->picksFor('alice'), 'nothing was stored');
    }

    /*
     * The refusal is about being out, not about week 1: a player knocked out
     * later is stopped the same way, and the week named is the week they went.
     */
    public function testTheRefusalNamesTheWeekThePlayerWentOut(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[0]], 2);

        $this->openWeek(2);
        $this->pick('alice', $teams[2]);
        $this->settleWeek(2, [$teams[3]], 3);

        $this->openWeek(3);
        $result = $this->pick('alice', $teams[4]);

        $this->assertStringContainsString('knocked out in week 2', $result);
    }

    /*
     * A buy-back is the way back in, and it restores the ability to pick --
     * not just a row in the table. This is the case the commissioner actually
     * relies on every September.
     */
    public function testABoughtBackPlayerMayPickAgain(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[1]], 2);

        $this->openWeek(2);
        $this->assertStringContainsString('knocked out in week 1', $this->pick('alice', $teams[2]));

        $this->store->grantBuyback('alice');
        $result = $this->pick('alice', $teams[2]);

        $this->assertStringContainsString('Pick recorded for week 2', $result);
        $this->assertSame($teams[2], $this->store->picksFor('alice')[2] ?? null);
    }

    /*
     * Being out does not empty the dropdown. Every team is still listed and
     * every one is disabled, so the control explains itself instead of looking
     * like a page that failed to load its teams.
     */
    public function testTheDropdownTellsAnEliminatedPlayerWhyNothingIsPickable(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[1]], 2);
        $this->openWeek(2);

        $options = get_team_options_html('alice');

        $this->assertSame(
            count($teams),
            substr_count($options, 'disabled'),
            'every team should be disabled, and none of them missing'
        );
        $this->assertTeamUnavailable($options, $teams[5], 'Knocked out in week 1');
    }

    /*
     * A player still in is not touched by any of this.
     */
    public function testAPlayerStillInKeepsAnOrdinaryDropdown(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[0]], 2);
        $this->openWeek(2);

        $this->assertTeamSelectable(get_team_options_html('alice'), $teams[5]);
        $this->assertStringContainsString('Pick recorded for week 2', $this->pick('alice', $teams[2]));
    }

    /*
     * The case the pool is actually in: bought back after week 1, then backed
     * a winner in week 2.
     *
     * A buy-back forgives week 1 for the rest of the season, so it keeps being
     * applied long after week 1 -- which is exactly why the second failure has
     * to be the one that sticks. Losing both weeks must end the season, not be
     * forgiven twice by the same list.
     */
    public function testABoughtBackPlayerWhoFailsWeekTwoIsStoppedForGood(): void
    {
        $teams = Teams::all();
        $this->register('alice');

        /* Out in week 1, then bought back. */
        $this->openWeek(1);
        $this->pick('alice', $teams[0]);
        $this->settleWeek(1, [$teams[1]], 2);
        $this->store->grantBuyback('alice');
        $this->assertSame(1, $this->survivors(), 'the buy-back put her back in');

        /* Week 2: she picks, and her team wins. */
        $this->openWeek(2);
        $this->assertStringContainsString('Pick recorded for week 2', $this->pick('alice', $teams[2]));
        $this->settleWeek(2, [$teams[3]], 3);
        $this->assertSame(0, $this->survivors(), 'week 2 is not forgiven');

        /* Week 3: the form is shut, and names week 2 rather than week 1. */
        $this->openWeek(3);
        $result = $this->pick('alice', $teams[4]);

        $this->assertStringContainsString('knocked out in week 2', $result);
        $this->assertArrayNotHasKey(3, $this->store->picksFor('alice'), 'nothing was stored');

        /* And it stays shut in week 4, with the buy-back still on record. */
        $this->openWeek(4);
        $this->assertStringContainsString('knocked out in week 2', $this->pick('alice', $teams[6]));
        $this->assertSame(['alice'], $this->store->buybacks(), 'the buy-back was never consumed');
    }
}
