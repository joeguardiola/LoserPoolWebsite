<?php

namespace TeamHandler;

include_once __DIR__ . "/../week_manager.php";
include_once __DIR__ . "/pick_handler.php";

use LoserPool\Nfl\Teams;
use function PickHandling\ph_get_user_picks_list;
use function PickHandling\ph_eliminated_in;

/*
 * Each option carries its own crest and club colour, so the enhanced picker
 * needs no second copy of the team map on the client.
 */
function ph_team_option(string $team, ?string $unavailableBecause = null): string
{
    $attributes = "";

    $logo = Teams::logoPath($team);
    if ($logo !== null) {
        $attributes .= " data-logo='" . $logo . "'";
    }

    $color = Teams::color($team);
    if ($color !== null) {
        $attributes .= " data-color='" . $color . "'";
    }

    if ($unavailableBecause !== null) {
        /* disabled keeps it unselectable in the native control too, not just
           in the enhanced picker, so the rule holds without JavaScript. */
        $attributes .= " disabled data-reason='" . htmlspecialchars($unavailableBecause) . "'";
    }

    return "<option" . $attributes . ">" . $team . "</option>";
}

function get_team_options_html($user = ""): string
{
    $options_list = "";

    $options_list .= "<select id='teams' name='team'>";
    $users_picks = [];

    if ($user != "") {
        $users_picks = ph_get_user_picks_list($user);
    }

    /*
     * Every team is listed. Unavailable ones are shown greyed out and
     * unselectable, with the reason, rather than omitted: a team that is simply
     * missing is indistinguishable from one that never existed, and a player
     * hunting for it cannot tell whether it is on a bye, playing before the
     * deadline, or one they already used.
     */
    $week = get_current_week();
    $reasons = get_INELIGIBLE_reasons($week);
    $this_weeks_pick = $users_picks[$week] ?? null;

    /*
     * A player who is out cannot pick at all, so every team carries the same
     * reason. The list is still rendered in full and disabled rather than
     * emptied, for the same reason one unavailable team is: an empty control,
     * or a search that matches nothing, reads as a broken page rather than an
     * answer. ph_add_pick() enforces this independently -- the disabled
     * option stops a person clicking, and is worth nothing against a request
     * that never came from this dropdown.
     */
    $out_week = $user != "" ? ph_eliminated_in($user) : null;
    if ($out_week !== null) {
        $options_list .= "<option value='' selected>Out in week " . $out_week . "</option>";
        foreach (Teams::all() as $team) {
            $options_list .= ph_team_option($team, 'Knocked out in week ' . $out_week);
        }
        return $options_list . "</select>";
    }

    /*
     * Two buckets, alphabetical within each. Scattering the unavailable teams
     * through the list pushes the ones that can actually be picked down behind
     * them: by the middle of the season most of the openings are used, so the
     * list opens on a wall of greyed rows and the player scrolls past teams
     * they cannot choose to reach the ones they can. Sorting them last keeps
     * every team listed, which is the point, without making the list start
     * with the useless half.
     */
    $selectable = "";
    $unavailable = "";

    /* This week's own pick leads, so the current choice is what the closed
       control shows, and so the native select lands on it with no JavaScript. */
    $current = $this_weeks_pick !== null ? ph_team_option($this_weeks_pick) : "";

    foreach (Teams::all() as $team) {
        if ($team === $this_weeks_pick) {
            continue;
        }

        $used_in = array_search($team, $users_picks, true);
        if ($used_in !== false) {
            $unavailable .= ph_team_option($team, 'Used in week ' . $used_in);
            continue;
        }

        $reason = $reasons[$team] ?? null;
        if ($reason !== null) {
            $unavailable .= ph_team_option($team, $reason);
            continue;
        }

        $selectable .= ph_team_option($team);
    }

    $options_list .= $current . $selectable . $unavailable;
    $options_list .= "</select>";
    return $options_list;
}
