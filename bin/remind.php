#!/usr/bin/env php
<?php

/*
 * Mails the players who have not picked yet, twelve hours before the lock.
 *
 * Scheduled hourly rather than once a week. The send window is decided here,
 * in the pool's own timezone, because a cron fixed in UTC drifts an hour when
 * America/Chicago leaves daylight saving in November -- and a reminder that
 * arrives at 11pm Thursday for the back half of the season is the kind of
 * fault nobody reports, they just stop trusting it.
 *
 * Every send is recorded, so running it twelve times in a Saturday mails
 * nobody twice.
 *
 *     php bin/remind.php              # send, if this is the window
 *     php bin/remind.php --dry-run    # print who would be mailed, send nothing
 *     php bin/remind.php --force      # ignore the window, still skip the sent
 */

require_once __DIR__ . '/../src/week_manager.php';
/* For ph_standings(), which is where the elimination rule and the buy-back
   list are already wired together. week_manager.php does not pull it in. */
require_once __DIR__ . '/../src/Handlers/pick_handler.php';

use LoserPool\Nfl\Teams;
use LoserPool\Pool\Reminders;
use LoserPool\Pool\Rules;
use LoserPool\Pool\SeasonConfig;
use LoserPool\Storage\PoolStore;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$force = in_array('--force', $args, true);

if (array_intersect($args, ['--help', '-h']) !== []) {
    fwrite(STDERR, "usage: php bin/remind.php [--dry-run] [--force]\n");
    exit(1);
}

$now = lp_now();

if (!$force && !Reminders::isSendWindow($now)) {
    echo "Not the send window (" . $now->format('D H:i T') . "). Nothing to do.\n";
    exit(0);
}

$week = get_current_week();

/*
 * A week nobody can pick in has nothing to chase. 2026 week 18 is played
 * entirely on Saturday, so all 32 teams are blocked and the pick form is shut
 * for everyone -- mailing the pool to ask for a pick they cannot make would be
 * the site telling them it is broken.
 */
$ineligible = get_INELIGIBLE_teams($week);
if (count($ineligible) >= count(Teams::all())) {
    echo "Week $week has no pickable teams. Nothing to remind.\n";
    exit(0);
}

$store = lp_store();
$standings = PickHandling\ph_standings();
$allPicks = $store->allPicks();

/*
 * Asked twice on purpose: without the sent list, and with it. A job whose only
 * visibility is a workflow log has to distinguish "nobody owes a pick" from
 * "they owe one and have already been told" -- the two have the same empty
 * result and very different meanings on a Saturday night.
 */
$outstanding = Reminders::due($standings, $allPicks, $week);
$due = Reminders::due($standings, $allPicks, $week, $store->remindersSent($week));

if ($due === []) {
    echo $outstanding === []
        ? "Week $week: everyone still in has picked. Nothing to send.\n"
        : "Week $week: " . count($outstanding) . " still owe a pick, all already reminded. Nothing to send.\n";
    exit(0);
}

if ($dryRun) {
    echo "Week $week: " . count($due) . " would be reminded.\n";
    foreach ($due as $username) {
        echo "  $username\n";
    }
    exit(0);
}

$mailer = lp_mailer();
if ($mailer === null) {
    fwrite(STDERR, "No mailer configured. Set LP_RESEND_API_KEY and LP_MAIL_FROM.\n");
    exit(1);
}

$sent = 0;
$failed = 0;

foreach ($due as $username) {
    $address = $store->emailFor($username);
    if ($address === null) {
        /*
         * Registration requires an address, so this is a row that should not
         * exist. Counted rather than fatal: one broken row must not stop the
         * other forty reminders.
         */
        fwrite(STDERR, "No address for $username\n");
        $failed++;
        continue;
    }

    /*
     * No link. The mail is sent from one domain and the pool is served from
     * another, and a message whose only link points somewhere unrelated to its
     * sender is the shape phishing has -- filters score it accordingly, which
     * is part of why the first sends were filed as spam. Everyone in the pool
     * already knows where to go.
     */
    $subject = "Loser Pool week $week: your pick is due Saturday";
    $body = "You have not entered a pick for week $week yet.\n\n"
        . "Picks are due 11:59pm Central on Saturday. Not picking counts as a\n"
        . "failed week, and there are no buy-backs after week 1.\n\n"
        . "Head to the pool site and get your pick in.\n\n"
        . "Your username is $username.\n";

    if (!$mailer->send($address, $subject, $body)) {
        /*
         * The address is deliberately absent from this line. The workflow that
         * runs this job prints its output into a public repository's logs.
         */
        fwrite(STDERR, "Failed for $username: " . (string) $mailer->lastError() . "\n");
        $failed++;
        continue;
    }

    if ($store->recordReminder($username, $week, $now->format(DATE_ATOM)) !== PoolStore::OK) {
        fwrite(STDERR, "Sent to $username but could not record it\n");
    }

    $sent++;
}

echo "Week $week: $sent reminded, $failed failed.\n";
exit($failed > 0 ? 1 : 0);
