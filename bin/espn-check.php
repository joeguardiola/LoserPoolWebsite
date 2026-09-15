#!/usr/bin/env php
<?php

/*
 * Answers one question: can this machine actually reach ESPN?
 *
 * It matters because the failure is silent by design. EspnClient falls back
 * through cache and committed snapshots, so a site that cannot reach ESPN at
 * all still renders correctly -- it just stops learning results, and the picks
 * grid never colours in. Without this check that looks like "the results
 * aren't updating yet" rather than "outbound HTTP is blocked".
 *
 * Run it on the web host:
 *
 *     php bin/espn-check.php
 *
 * Exit status is 0 only if live data was actually fetched.
 */

require_once __DIR__ . '/../src/autoload.php';

use LoserPool\Nfl\EspnClient;
use LoserPool\Pool\SeasonConfig;

$cacheDir = SeasonConfig::cacheDir();
$snapshotDir = SeasonConfig::snapshotDir();

/* TTL 0 so this always attempts the network rather than answering from cache. */
$client = new EspnClient($cacheDir, SeasonConfig::timezone(), $snapshotDir, 0, SeasonConfig::HTTP_TIMEOUT_SECONDS);

echo "Loser Pool connectivity check\n";
echo str_repeat('-', 46), "\n";
printf("PHP version      %s\n", PHP_VERSION);
printf("curl available   %s\n", function_exists('curl_init') ? 'yes' : 'no (falls back to file_get_contents)');
printf("allow_url_fopen  %s\n", ini_get('allow_url_fopen') ? 'on' : 'off');
printf("cache writable   %s\n", is_writable(is_dir($cacheDir) ? $cacheDir : dirname($cacheDir)) ? 'yes' : 'NO');

/*
 * Probed with the current-week lookup, never with a week's schedule.
 *
 * A finished week is cached as final and never expires, so asking for one makes
 * no HTTP request at all -- this reported "no request made / PROBLEM: no live
 * data" from the moment week 1 went final, on a host that was reaching ESPN
 * perfectly well. The current-week lookup is never final, so at TTL 0 it always
 * goes to the network, which is the only thing that can answer the question
 * this tool exists to ask.
 */
$current = $client->currentSeasonWeek();
$sources = $client->sources();
$source = $sources['nfl-current'] ?? 'unavailable';
$status = $client->lastHttpStatus();

echo str_repeat('-', 46), "\n";
printf("HTTP status      %s\n", $status === null ? 'no request made' : $status);
printf("Data source      %s\n", $source);
printf("ESPN reports     %s\n", $current === null
    ? 'nothing readable'
    : sprintf('%d week %d (season type %d)', $current['year'], $current['week'], $current['seasonType']));

$snapshots = glob($snapshotDir . '/*.json') ?: [];
printf("Snapshots        %d files\n", count($snapshots));
if ($snapshots !== []) {
    /* generated_at, not mtime: in a container mtime is the image build date. */
    $recorded = json_decode((string) file_get_contents($snapshots[0]), true);
    $generated = isset($recorded['generated_at']) ? strtotime($recorded['generated_at']) : false;
    $ageDays = $generated === false ? 0 : (int) floor((time() - $generated) / 86400);
    printf("Snapshot age     %d day(s)\n", $ageDays);
    if ($ageDays > 21) {
        echo "  ! Snapshots are stale. Late-season kickoff times are provisional\n";
        echo "    and get flexed; re-run bin/refresh-snapshots.php.\n";
    }
}

echo str_repeat('-', 46), "\n";
if ($source === 'live') {
    echo "OK: live data reached ESPN. Results will update on their own.\n";
    exit(0);
}

echo "PROBLEM: no live data.\n";
echo "The site will still work, using ";
echo $source === 'unavailable' ? "no schedule data at all" : "the $source copy";
echo ",\nbut RESULTS WILL NEVER UPDATE because only live data carries scores.\n\n";
echo "Likely causes: the host blocks outbound HTTP, or ESPN rejected the\n";
echo "request (it 403s some user agents).\n";
exit(1);
