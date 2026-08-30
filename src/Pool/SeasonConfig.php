<?php

namespace LoserPool\Pool;

use DateTimeImmutable;
use DateTimeZone;

/*
 * Everything that changes from one season to the next, in one place.
 *
 * Rolling the pool over to a new year should be an edit to this file and
 * nothing else.
 */
final class SeasonConfig
{
    /* The season to run. Bump this in the offseason. */
    public const YEAR = 2026;

    /* Suffix for that season's database tables, e.g. Users_26 / Picks_26. */
    public const TABLE_SUFFIX = '26';

    /* The pool's home timezone; every deadline is expressed in it. */
    public const TIMEZONE = 'America/Chicago';

    /*
     * Teams playing on these days cannot be picked, because their games start
     * before the pick deadline of 11:59 p.m. Saturday.
     *
     * Saturday is on this list by an explicit commissioner decision, and it
     * costs the pool week 18. Saturday games kick off hours before a Saturday
     * night deadline, so leaving them pickable let a player watch a team lose
     * and then pick it -- the same hole the Wed/Thu/Fri entries close.
     *
     * The price: 2026 week 18 is played entirely on Saturday (16 games, all 32
     * teams). Under this list every team in that week is unpickable, so no
     * player can submit a week 18 pick and the pool cannot play its final week
     * through the site. That is known and accepted; week 18 is the
     * commissioner's to settle off-site. Do not "fix" it by quietly dropping
     * Saturday from this list.
     */
    public const BLOCKED_KICKOFF_DAYS = ['Wed', 'Thu', 'Fri', 'Sat'];

    public const REGULAR_SEASON_WEEKS = 18;

    /*
     * Tuesday before the season opener -- pool weeks run Tuesday to Monday.
     * Only used when ESPN cannot be reached.
     */
    public const WEEK_ONE_START = "2026-09-08 00:00:00";

    /* How long an unfinished week's data stays fresh. Finished weeks never expire. */
    public const LIVE_CACHE_TTL_SECONDS = 600;

    /* Give up on ESPN quickly; a slow page is worse than a stale one. */
    public const HTTP_TIMEOUT_SECONDS = 5;

    /*
     * ESPN answers 403 to some user agents -- a bare product token like
     * "LoserPool/1.0" is rejected, and so is a plain copied browser string.
     * This "Mozilla/5.0 (compatible; ...)" form is accepted. The failure is
     * invisible from the site (a 403 just falls through to cached or snapshot
     * data), so bin/espn-check.php exists to surface it.
     */
    public const USER_AGENT = 'Mozilla/5.0 (compatible; LoserPool/1.0; +https://github.com/ashufelt/LoserPoolWebsite)';

    /* Project root: one level above src/. */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /*
     * Written at runtime, so it lives outside the docroot and outside git.
     * On the container this is ephemeral, which is fine -- a cold start just
     * re-fetches.
     */
    public static function cacheDir(): string
    {
        $configured = getenv('LP_CACHE_DIR');
        return $configured !== false && $configured !== '' ? $configured : self::root() . '/var/cache';
    }

    /* Committed schedule data: the offline fallback if ESPN is unreachable. */
    public static function snapshotDir(): string
    {
        return self::root() . '/data/snapshots';
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function weekOneStart(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::WEEK_ONE_START, self::timezone());
    }
}
