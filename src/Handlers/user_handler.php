<?php

namespace UserHandling;

include_once __DIR__ . "/../bootstrap.php";
include_once __DIR__ . "/../week_manager.php";

use LoserPool\Pool\Standings;
use LoserPool\Storage\PoolStore;


/*
 * $problem comes back with the reason a registration was refused.
 *
 * Every refusal used to look the same from the outside: one sentence listing
 * everything that might have been wrong. The browser catches most of these
 * first, but only if its own rules agree with these, and a request that did
 * not come from the form is not checked at all.
 */
function uh_add_user(
    string $name,
    string $email,
    string $new_user,
    string $pin,
    string $repin,
    ?string &$problem = null
): bool {
    $store = lp_store();
    $problem = null;
    $new_user = htmlspecialchars(trim($new_user));
    $name = htmlspecialchars(trim($name));
    $email = htmlspecialchars(trim($email));

    /*
     * Checked before anything else, because nothing the player types can make
     * a closed pool accept them, and a form that validates six fields and then
     * says "we are not taking entries" wastes the effort.
     */
    if (!lp_registration_is_open()) {
        $problem = "Registration closed when week 1 locked. The pool is under way,"
            . " so there is nothing to join until next season.";
        return false;
    }

    if ($name === "" || $email === "" || $new_user === "" || $pin === "") {
        $problem = "Fill in every box.";
        return false;
    }

    if ($pin !== $repin) {
        $problem = "The two PINs do not match.";
        return false;
    }

    if (preg_match('/^\d{4}$/', $pin) !== 1) {
        $problem = "A PIN is four digits.";
        return false;
    }

    /*
     * The username is a key: it identifies picks, appears in the dropdown and
     * travels in form posts. The name beside it is free text and is only ever
     * displayed, so it is not held to this.
     */
    if (preg_match('/^\w{3,20}$/', $new_user) !== 1) {
        $problem = "A username is 3 to 20 characters, letters, numbers and underscores, with no spaces.";
        return false;
    }

    $code = $store->addUser($name, $email, $new_user, $pin);
    if ($code === PoolStore::OK) {
        return true;
    }

    $problem = $code === PoolStore::USER_EXISTS
        ? "That username is already taken. Try another, or add _2 on the end for a second entry."
        : "Could not save the registration. Try again.";

    return false;
}

/*
 * Players still in come first, then players who are out, labelled as such.
 *
 * Out players are listed rather than removed. Two reasons, and the second is
 * the one that would hurt: a name that is simply absent reads as a lost
 * registration rather than as an elimination, and buy-backs are recorded by
 * hand after the fact, so between a week-one loss and the commissioner running
 * bin/buyback.php a player who has paid to stay in would find themselves
 * unable to submit anything at all.
 */
function uh_get_user_option_list_html(): string
{
    $store = lp_store();

    $users = $store->allUsernames();
    if (!is_countable($users)) {
        return '';
    }
    sort($users, SORT_NATURAL | SORT_FLAG_CASE);

    $standings = Standings::build(
        $store->allPicks(),
        'check_loser',
        $store->buybacks(),
        $users,
        lp_weeks_requiring_a_pick()
    );

    $still_in = '';
    $out = '';
    foreach ($users as $user) {
        $row = $standings[$user] ?? null;
        if ($row !== null && $row['status'] === Standings::OUT) {
            $out .= '<option value="' . $user . '" data-out="' . $row['outWeek'] . '">'
                . $user . ' (out &middot; wk ' . $row['outWeek'] . ')</option>';
            continue;
        }
        $still_in .= '<option value="' . $user . '">' . $user . '</option>';
    }

    if ($out === '') {
        return $still_in;
    }

    /*
     * An optgroup rather than styling the options, because that is the part of
     * a native select every browser actually renders differently: option
     * colour is honoured on some desktop browsers and ignored outright on
     * iOS, where most of these picks are made. The group heading greys and
     * separates the eliminated players everywhere.
     *
     * Not disabled. They can still submit, and a week-one buy-back is recorded
     * by hand afterwards, so disabling would lock out a player who has paid to
     * stay in until the commissioner got round to it.
     */
    return $still_in
        . '<optgroup label="Out of the pool">' . $out . '</optgroup>';
}
