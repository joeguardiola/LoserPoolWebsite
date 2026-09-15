#!/usr/bin/env php
<?php

/*
 * Sets or corrects a player's pick for a given week.
 *
 * This is a commissioner's override and it deliberately goes around the rules
 * the site enforces at pick time. That is the entire point: the site refuses a
 * pick for a week that is locked or already played, which is exactly the
 * situation this tool exists for. It is the counterpart to bin/buyback.php --
 * something decided by a person, recorded by hand.
 *
 *     php bin/set-pick.php <username> <week> <team>
 *     php bin/set-pick.php --show <username>
 *
 * It was written for the 15 September 2026 incident, when the site reported a
 * settled week as current and three players edited their week 1 picks with the
 * results already known. Putting those back by hand meant either raw SQL
 * against live player data or a tool that can be read, reviewed and re-run --
 * and this repository's history is largely a list of things that went wrong
 * when a change was applied without one.
 *
 * What it will not do quietly:
 *   - write for a user who does not exist
 *   - accept a team name that is not an exact NFL club name
 *   - accept a week outside the season
 *   - break the no-repeat rule -- that needs --force, and says so
 *
 * It does not check kickoff days or byes. Those are pick-time rules about what
 * a player may choose; a correction is the commissioner deciding what the pick
 * *was*, and the pick being corrected has usually already been played.
 */

require_once __DIR__ . '/../src/bootstrap.php';

use LoserPool\Nfl\Teams;
use LoserPool\Pool\SeasonConfig;
use LoserPool\Storage\PoolStore;

$store = lp_store();
$arguments = array_slice($argv, 1);

$force = false;
foreach ($arguments as $index => $value) {
    if ($value === '--force') {
        $force = true;
        unset($arguments[$index]);
    }
}
$arguments = array_values($arguments);

function usage(): void
{
    fwrite(STDERR, "usage: php bin/set-pick.php <username> <week> <team> [--force]\n");
    fwrite(STDERR, "       php bin/set-pick.php --show <username>\n");
    exit(1);
}

if ($arguments === [] || $arguments[0] === '--help') {
    usage();
}

/* Read-only mode, so the state can be inspected before and after a change. */
if ($arguments[0] === '--show') {
    $username = $arguments[1] ?? null;
    if ($username === null) {
        usage();
    }
    if (!$store->userExists($username)) {
        fwrite(STDERR, "No such user: $username\n");
        exit(1);
    }
    $picks = $store->picksFor($username);
    if ($picks === []) {
        echo "$username has no picks.\n";
        exit(0);
    }
    ksort($picks);
    foreach ($picks as $week => $team) {
        printf("  week %-2d  %s\n", $week, $team);
    }
    exit(0);
}

if (count($arguments) < 3) {
    usage();
}

[$username, $rawWeek, $team] = [$arguments[0], $arguments[1], $arguments[2]];

if (!$store->userExists($username)) {
    fwrite(STDERR, "No such user: $username\n");
    exit(1);
}

if (!ctype_digit((string) $rawWeek)) {
    fwrite(STDERR, "Week must be a number, got: $rawWeek\n");
    exit(1);
}
$week = (int) $rawWeek;
if ($week < 1 || $week > SeasonConfig::REGULAR_SEASON_WEEKS) {
    fwrite(STDERR, "Week must be between 1 and " . SeasonConfig::REGULAR_SEASON_WEEKS . ", got: $week\n");
    exit(1);
}

/*
 * Exact match only. A near miss such as "Miami" would otherwise be written
 * verbatim and silently never match a result, because every rule compares the
 * stored string against ESPN's display name.
 */
if (!in_array($team, Teams::all(), true)) {
    fwrite(STDERR, "Not an NFL club name: $team\n");
    $close = array_values(array_filter(Teams::all(), function (string $candidate) use ($team) {
        return stripos($candidate, $team) !== false;
    }));
    if ($close !== []) {
        fwrite(STDERR, "Did you mean: " . implode(', ', $close) . "\n");
    }
    exit(1);
}

$existing = $store->picksFor($username);

/* The no-repeat rule is the one a correction can quietly break. */
$clash = [];
foreach ($existing as $otherWeek => $otherTeam) {
    if ((int) $otherWeek !== $week && $otherTeam === $team) {
        $clash[] = (int) $otherWeek;
    }
}
if ($clash !== [] && !$force) {
    fwrite(STDERR, "$username already used $team in week " . implode(', ', $clash) . ".\n");
    fwrite(STDERR, "No repeat picks are allowed all season. Re-run with --force to do it anyway.\n");
    exit(1);
}

$was = $existing[$week] ?? null;
if ($was === $team) {
    echo "$username already has $team for week $week. Nothing to do.\n";
    exit(0);
}

$result = $store->savePick($username, $team, $week);
if ($result === PoolStore::NO_SUCH_USER) {
    fwrite(STDERR, "No such user: $username\n");
    exit(1);
}
if ($result !== PoolStore::OK) {
    fwrite(STDERR, "Could not save the pick for $username.\n");
    exit(1);
}

/*
 * Read back rather than trusting the return code. This repository has already
 * been bitten by a check that confirmed an assumption instead of testing it.
 */
$after = $store->picksFor($username);
if (($after[$week] ?? null) !== $team) {
    fwrite(STDERR, "Wrote the pick but read back " . var_export($after[$week] ?? null, true) . ". Investigate.\n");
    exit(1);
}

printf("%s week %d: %s -> %s\n", $username, $week, $was === null ? '(none)' : $was, $team);
if ($clash !== []) {
    printf("  warning: also used in week %s, forced\n", implode(', ', $clash));
}
