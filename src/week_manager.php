<?php

/*
 * Season logic for the pool.
 *
 * This file used to be ~670 lines, almost all of it hand-typed arrays of team
 * names: who lost each week, who won, and who was unpickable. Someone had to
 * edit PHP source and redeploy every week for the site to stay correct.
 *
 * It is now a thin procedural facade over LoserPool\ classes. The function
 * signatures are unchanged so existing callers (pick_handler, team_handler,
 * get_week) keep working, but the answers come from ESPN's schedule data.
 *
 * Everything here memoises. get_current_week() alone is called several times
 * per request -- including inside the dropdown loop -- and without a static
 * cache that would become a burst of HTTP requests on every page view.
 */

require_once __DIR__ . '/autoload.php';

use LoserPool\Nfl\EspnClient;
use LoserPool\Nfl\Schedule;
use LoserPool\Nfl\ScheduleSource;
use LoserPool\Nfl\Teams;
use LoserPool\Pool\Rules;
use LoserPool\Pool\SeasonConfig;
use LoserPool\Pool\WeekResolver;

/*
 * The process-wide schedule source. Passing $override swaps in a different
 * implementation -- used by tests and the CLI harness to run without network.
 */
function lp_schedule_source(?ScheduleSource $override = null): ScheduleSource
{
    static $source = null;

    if ($override !== null) {
        $source = $override;
        lp_reset_week_cache();
    }

    if ($source === null) {
        $source = new EspnClient(
            SeasonConfig::cacheDir(),
            SeasonConfig::timezone(),
            SeasonConfig::snapshotDir(),
            SeasonConfig::LIVE_CACHE_TTL_SECONDS,
            SeasonConfig::HTTP_TIMEOUT_SECONDS
        );
    }

    return $source;
}

function lp_reset_week_cache(): void
{
    lp_week_schedule(0, true);
    get_current_week(true);
}

function lp_week_schedule(int $week, bool $reset = false): Schedule
{
    static $schedules = [];

    if ($reset) {
        $schedules = [];
        return Schedule::emptySchedule();
    }

    if (!array_key_exists($week, $schedules)) {
        $schedules[$week] = lp_schedule_source()->weekSchedule(SeasonConfig::YEAR, $week);
    }

    return $schedules[$week];
}

/*
 * The clock, in one place and overridable.
 *
 * Everything time-dependent here -- the pick lock, whether picks are hidden,
 * the offline week fallback -- reads this. Without an override those rules
 * could only be tested on the days of the week they happen to concern, which
 * is how the January day-of-year bug survived as long as it did.
 */
function lp_clock(?DateTimeImmutable $freezeAt = null, bool $release = false): ?DateTimeImmutable
{
    static $frozen = null;

    if ($release) {
        $frozen = null;
    } elseif ($freezeAt !== null) {
        $frozen = $freezeAt;
    }

    return $frozen;
}

function lp_now(): DateTimeImmutable
{
    return lp_clock() ?? new DateTimeImmutable('now', SeasonConfig::timezone());
}

function get_current_week(bool $reset = false): int
{
    static $week = null;

    if ($reset) {
        $week = null;
        return 1;
    }

    if ($week === null) {
        $week = WeekResolver::resolve(
            lp_schedule_source()->currentSeasonWeek(),
            SeasonConfig::YEAR,
            lp_now(),
            SeasonConfig::weekOneStart(),
            SeasonConfig::REGULAR_SEASON_WEEKS
        );
    }

    return $week;
}

function is_sunday_or_monday(): bool
{
    $day = lp_now()->format('D');
    return $day === 'Sun' || $day === 'Mon';
}

/*
 * Is the season actually underway? Callers previously asked this with
 * `intval(date('z')) > 245`, i.e. "past early September". That silently
 * inverted in January, when the day-of-year resets: during weeks 17 and 18 the
 * Sunday/Monday pick lock stopped applying and picks stayed hidden forever.
 */
function lp_season_in_progress(): bool
{
    return lp_now() >= SeasonConfig::weekOneStart();
}

/* Picks are locked once the main slate is underway. */
function lp_picks_are_locked(): bool
{
    return lp_season_in_progress() && is_sunday_or_monday();
}

/* Other players' current-week picks stay hidden until the slate starts. */
function lp_picks_are_hidden(): bool
{
    return !lp_picks_are_locked();
}

/*
 * Registration closes when week one locks, and does not reopen.
 *
 * A survivor pool cannot take entrants once it is running: a player joining in
 * week three would carry no losses and be level with someone who has survived
 * three weeks, and the buy-back rule -- week one only -- exists precisely
 * because week one is the boundary the pool is willing to move.
 *
 * Deliberately a one-way gate rather than lp_picks_are_locked(), which is true
 * on Sunday and Monday of every week and false again on Tuesday. Registration
 * shuts on the first Sunday and stays shut for the season.
 */
function lp_registration_is_open(): bool
{
    if (!lp_season_in_progress()) {
        return true;
    }

    return get_current_week() === 1 && !lp_picks_are_locked();
}

/*
 * Teams that cannot be picked this week: byes, plus teams playing before the
 * 11:59 p.m. Saturday deadline (Wed/Thu/Fri/Sat).
 */
function get_INELIGIBLE_teams($week): array
{
    return Rules::ineligibleTeams(
        lp_week_schedule((int) $week),
        Teams::all(),
        SeasonConfig::BLOCKED_KICKOFF_DAYS
    );
}

/*
 * Why each unavailable team is unavailable this week, as team => reason.
 * Used to explain the greyed out entries rather than silently omitting them.
 */
function get_INELIGIBLE_reasons($week): array
{
    return Rules::unavailabilityReasons(
        lp_week_schedule((int) $week),
        Teams::all(),
        SeasonConfig::BLOCKED_KICKOFF_DAYS
    );
}

/* 1 if the pick came in (team lost), -1 if it did not, 0 if undecided. */
function check_loser($week, $team): int
{
    return Rules::checkPick(lp_week_schedule((int) $week), (string) $team);
}
