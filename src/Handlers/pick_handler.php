<?php

namespace PickHandling;

include_once __DIR__ . "/../bootstrap.php";
include_once __DIR__ . "/../week_manager.php";

use LoserPool\Nfl\Teams;
use LoserPool\Pool\Standings;
use LoserPool\Pool\SeasonConfig;


/*
 * The handlers' only channel back to the player. These used to be bare <h4>
 * elements, which rendered as headings in the middle of the page and looked
 * like a section title rather than an answer to what had just been done.
 */
function ph_status_ok(string $message): string
{
    return "<p class='form-status form-status-ok' role='status'>" . $message . "</p>";
}

function ph_status_bad(string $message): string
{
    return "<p class='form-status form-status-bad' role='status'>" . $message . "</p>";
}

/*
 * The whole table's standings.
 *
 * Two callers need them now: the picks table renders them, and ph_add_pick()
 * refuses a pick from a player who is out. The caller may hand in rows it has
 * already read, so a request that needs both the grid and the standings
 * queries the store once rather than twice, and cannot answer the same
 * question two ways within one response.
 *
 * @return array<string,array{status:string,outWeek:?int,correct:int}>
 */
function ph_standings(?array $allPicks = null, ?array $usernames = null): array
{
    $store = lp_store();

    return Standings::build(
        $allPicks ?? $store->allPicks(),
        'check_loser',
        $store->buybacks(),
        $usernames ?? $store->allUsernames(),
        lp_weeks_requiring_a_pick()
    );
}

/*
 * The week a player was knocked out, or null while they are still in.
 *
 * Everything that decides this fails open: an unfinished or unloadable week
 * scores PICK_UNDECIDED and is skipped by lp_weeks_requiring_a_pick(), so a
 * schedule we could not fetch leaves the whole pool reading as still in. That
 * is the safe direction for a gate -- an ESPN outage cannot lock a living
 * player out of the week they are trying to enter.
 */
function ph_eliminated_in(string $username): ?int
{
    $standings = ph_standings();
    $row = $standings[$username] ?? null;

    if ($row === null || $row['status'] === Standings::IN) {
        return null;
    }

    return $row['outWeek'];
}

function ph_add_pick(string $userin, string $teamin, string $pinin): string
{
    $store = lp_store();
    $user = htmlspecialchars($userin);
    $week = get_current_week();
    $team = htmlspecialchars($teamin);
    $pickpin = $pinin;
    if ($store->userExists($user) == false) {
        return ph_status_bad("That username does not exist.");
    }
    $week_number = intval($week);
    $users_picks = $store->picksFor($user);
    $ineligible = get_INELIGIBLE_reasons($week_number);

    /*
     * The no-repeat rule is about the other weeks. This week's own pick is the
     * one a player is allowed to overwrite, and the page says so: submit again
     * and the latest pick replaces the last one. Counting it made resubmitting
     * the same team a repeat of itself, so pressing the button twice, or
     * changing your mind and changing it back, answered "already used this
     * season" for a team the player had used nowhere but here.
     */
    $earlier_picks = $users_picks;
    unset($earlier_picks[$week_number]);
    $create_ecode = 0;
    if (!$store->verifyPin($user, $pickpin)) {
        return ph_status_bad("Username/PIN combo is not valid.");
    } else if (($out_week = ph_eliminated_in($user)) !== null) {
        /*
         * Checked after the PIN so the answer belongs to the player, not to
         * anyone who can type their username -- though the standings table
         * publishes the same fact, so this is order rather than secrecy.
         *
         * A player who bought back is not out: Standings::build() is given the
         * buy-back list and forgives week 1 for the names on it, which is why
         * this asks the standings rather than scoring week 1 here.
         */
        return ph_status_bad(
            "You were knocked out in week " . $out_week . ", so this pick cannot be entered."
            . " If that is wrong, email the commissioner."
        );
    } else if (in_array($team, $earlier_picks, true)) {
        return ph_status_bad("Cannot repeat a choice. " . $team . " has already been used this season.");
    } else if (!in_array($team, Teams::all(), true)) {
        /*
         * Nothing above this line established that $team is a team. The
         * dropdown can only offer real ones, but the dropdown is not the only
         * way to reach this function, and an unrecognised name would be stored
         * and then scored against a schedule that has never heard of it.
         */
        return ph_status_bad("Not a team in this league.");
    } else if (isset($ineligible[$team])) {
        /*
         * The rule that keeps the pool honest, enforced where it counts. The
         * option is disabled in the dropdown, which stops a person clicking it
         * and is worth nothing against a request that did not come from the
         * dropdown. The reason is the same wording the list shows.
         */
        return ph_status_bad(htmlspecialchars($ineligible[$team]) . ", so it cannot be picked this week.");
    } else if (lp_picks_are_locked()) {
       return ph_status_bad("Picks are locked on Sunday and Monday.");
    } else if (0 != ($create_ecode = $store->savePick($user, $team, $week_number))) {
        if ($create_ecode == 2) {
            return ph_status_bad("Invalid username.");
        } else if ($create_ecode == 1) {
            return ph_status_bad("Database error. Try viewing your pick, or submitting again.")
                . "<p class='hint'>If 'View my picks' does not show it, email "
                . "josephrguardiola@gmail.com before the deadline so the pick is not lost.</p>";
        }
    } else {
        return ph_status_ok(
                "Pick recorded for week " . $week_number . ": <strong>" . $team . "</strong>."
                . " Submitting again before Sunday replaces it."
            );
    }
}

/*
 * A team name with its crest.
 *
 * Falls back to the bare name when there is no crest: a pick stored under a
 * franchise that has since been renamed must still render.
 */
function ph_team_label(string $team): string
{
    $logo = Teams::logoPath($team);
    if ($logo === null) {
        return $team;
    }

    return "<img class='team-crest' src='" . $logo . "' alt='' width='22' height='22' loading='lazy'>"
        . "<span class='team-name'>" . $team . "</span>";
}

/*
 * $out_of_band marks the table for an htmx out-of-band swap, so a response
 * whose main target is somewhere else entirely can still refresh it. It is a
 * flag rather than a rewrite at the call site because the id lives here.
 */
function ph_get_picks_html_table(bool $out_of_band = false): string
{
    $store = lp_store();
    $hide_picks = lp_picks_are_hidden();
    $current_week = get_current_week();
    /*
     * The whole season so far, not a rolling window: a player's record is the
     * point of the table, and a window that scrolls off week 1 hides the picks
     * that eliminated most of the pool.
     */
    $start_week = 1;
    $end_week = max(1, min($current_week, SeasonConfig::REGULAR_SEASON_WEEKS));

    $users = $store->allUsernames();

    /* One query for the whole grid rather than one per player. */
    $all_picks = $store->allPicks();

    $standings = ph_standings($all_picks, $users);
    $still_in = Standings::stillIn($standings);

    /*
     * Players still in come first. The pool's endgame is about who is left,
     * and once half the table is eliminated an alphabetical list buries them.
     */
    $users = array_keys($standings);
    usort($users, static function ($a, $b) use ($standings) {
        $byStatus = ($standings[$a]['status'] === Standings::IN ? 0 : 1)
            <=> ($standings[$b]['status'] === Standings::IN ? 0 : 1);
        return $byStatus !== 0 ? $byStatus : strcasecmp($a, $b);
    });

    $oob = $out_of_band ? " hx-swap-oob='true'" : "";
    $picks_html_table = "
        <div id='picks_table'" . $oob . ">
            <p class='standings-summary'><strong>" . $still_in . "</strong> still in"
            . (count($standings) > 0 ? " of " . count($standings) : "") . "</p>
            <table class='pick_table'>
                <tr class='pick_table'>
                    <th class='pickcolumn1 pick_table'>Player</th>
                    <th class='pickHeader pick_table'>Status</th>";
    for ($i = $start_week; $i <= $end_week; $i++) {
        $picks_html_table .= "<th class='pickHeader pick_table'>Week " . ($i) . "</th>";
    }
    $picks_html_table .= "</tr>";

    foreach ($users as $user) {
        $row_class = $standings[$user]['status'] === Standings::IN ? "" : " player-out";
        $picks_html_table .= "<tr class='pick_table" . $row_class . "'>"
            . "<td class='pickcolumn1 pick_table'>" . $user . "</td>";

        if ($standings[$user]['status'] === Standings::IN) {
            $picks_html_table .= "<td class='pick_table status-in'>Still in</td>";
        } else {
            $picks_html_table .= "<td class='pick_table status-out'>Out &middot; wk "
                . $standings[$user]['outWeek'] . "</td>";
        }

        $users_picks = $all_picks[$user] ?? [];
        $out_week = $standings[$user]['outWeek'];
        for ($i = $start_week; $i <= $end_week; $i++) {
            $pick = "";
            $result_class = "";
            $mark = "";
            if (array_key_exists($i, $users_picks)) {
                $pick = $users_picks[$i];
                if ($hide_picks && ($i == $current_week)) {
                    $pick = "<span class='pick-pending'>Submitted</span>";
                } elseif ($out_week !== null && $i > $out_week) {
                    /*
                     * Picks made after the week that ended a player's season.
                     * Nothing stops an eliminated player carrying on, and
                     * people do, but scoring those picks paints a run of green
                     * survival ticks across a row whose status says the player
                     * is out. They are shown, greyed, and not scored.
                     */
                    $pick = "<span class='pick-void'>" . ph_team_label($pick) . "</span>";
                } else {
                    /*
                     * Result cells carry a glyph as well as a colour. Colour
                     * alone is unreadable for colour blind players, and these
                     * cells are the entire point of the table.
                     */
                    $team_result = check_loser($i, $pick);
                    $pick = ph_team_label($pick);
                    if ($team_result == -1) {
                        $result_class = " res-wrong";
                        $mark = "<span class='res-mark' aria-hidden='true'>&#10007;</span>";
                        $pick = "<span class='visually-hidden'>Out: </span>" . $pick;
                    } else if ($team_result == 1) {
                        $result_class = " res-correct";
                        $mark = "<span class='res-mark' aria-hidden='true'>&#10003;</span>";
                        $pick = "<span class='visually-hidden'>Survived: </span>" . $pick;
                    }
                }
            } elseif ($i == $current_week && $standings[$user]['status'] === Standings::IN) {
                /*
                 * An empty cell said nothing: a player who had not picked yet
                 * looked exactly like a column with no data in it, so the one
                 * question worth asking before the deadline -- who still has
                 * to get their pick in -- could not be answered from the table
                 * at all. Only for the current week, and only for players who
                 * are still in: a blank week for someone already out is not an
                 * outstanding pick.
                 */
                $pick = $hide_picks
                    ? "<span class='pick-none'>Not in yet</span>"
                    : "<span class='pick-none'>No pick</span>";
            }
            $picks_html_table .= "<td class='pick_table pick_team" . $result_class . "'>" . $mark . $pick . "</td>";
        }
        $picks_html_table .= "</tr>";
    }

    $picks_html_table .= "</table></div>";
    return $picks_html_table;
}

function ph_get_user_picks_html(string $user, string $pin)
{
    $store = lp_store();
    $user = htmlspecialchars($user);
    if (!$store->verifyPin($user, $pin)) {
        return ph_status_bad("Username/PIN combo is not valid.");
    }

    /*
     * Named, because this table is the only place a player can see their own
     * current-week pick: the standings hide it behind "Submitted" until the
     * slate starts, and that is the point of hiding it. Without a heading it
     * is one more grid on a page that already has one.
     */
    $picks_html_table = "<div class=users_picks>
                         <h3 class='users_picks_heading'>" . $user . "'s picks</h3>
                         <table class='users_picks'>
                            <tr class='users_picks'>
                                <th class='users_picks'>Week</th>
                                <th class='users_picks'>Pick</th>";

    $users_picks = $store->picksFor($user);
    for ($i = 1; $i <= get_current_week(); $i++) {
        $pick = "";
        if (array_key_exists($i, $users_picks)) {
            $pick = ph_team_label($users_picks[$i]);
        } elseif ($i == get_current_week()) {
            $pick = "<span class='pick-none'>Not picked yet</span>";
        }
        $picks_html_table .= "<tr class='users_picks'>
                                <td class='users_picks'>" . $i . "</td>
                                <td class='users_picks pick_team'>" . $pick . "</td></tr>";
    }
    $picks_html_table .= "</table>
                            <button hx-get='/picks/hide.php' hx-target='#one_set_of_picks' 
                                    hx-swap='innerHTML' class='hidepicks' type='submit' value='Hide picks'>
                                Hide picks
                            </button>           
                            </div>";
    return $picks_html_table;
}


/*
* Returns a user's picks as an associative array of week => team.
* Used by the team dropdown to hide teams the user has already picked.
*/
function ph_get_user_picks_list(string $user)
{
    $store = lp_store();
    $user = htmlspecialchars($user);
    return $store->picksFor($user);
}
