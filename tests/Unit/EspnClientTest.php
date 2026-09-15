<?php

namespace LoserPool\Tests\Unit;

use LoserPool\Nfl\EspnClient;
use LoserPool\Pool\SeasonConfig;
use LoserPool\Tests\FixtureLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/*
 * The caching and degradation ladder:
 *
 *   fresh cache -> live fetch -> stale cache -> bundled snapshot -> null
 *
 * The transport is injected, so none of this touches the network. That matters
 * beyond test speed: we cannot confirm the production host is allowed to make
 * outbound requests at all, so the offline branches are the ones most likely
 * to run in the real deployment.
 */
final class EspnClientTest extends TestCase
{
    private string $cacheDir;
    private string $snapshotDir;

    /** @var string[] */
    private array $requested = [];

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/loserpool-test-' . bin2hex(random_bytes(6));
        $this->cacheDir = $base . '/cache';
        $this->snapshotDir = $base . '/snapshots';
        mkdir($this->snapshotDir, 0775, true);
        $this->requested = [];
    }

    protected function tearDown(): void
    {
        foreach ([$this->cacheDir, $this->snapshotDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        @rmdir(dirname($this->cacheDir));
    }

    /** @param string[]|null[] $responses returned in order, then repeated */
    private function client(array $responses, int $ttl = 600): EspnClient
    {
        $queue = $responses;

        return new EspnClient(
            $this->cacheDir,
            SeasonConfig::timezone(),
            $this->snapshotDir,
            $ttl,
            5,
            function (string $url) use (&$queue) {
                $this->requested[] = $url;
                return count($queue) > 1 ? array_shift($queue) : ($queue[0] ?? null);
            }
        );
    }

    public function testFetchesAndParsesAWeek(): void
    {
        $client = $this->client([FixtureLoader::raw('2026-w01')]);

        $schedule = $client->weekSchedule(2026, 1);

        $this->assertCount(16, $schedule->games());
        $this->assertCount(1, $this->requested);
        $this->assertStringContainsString('week=1', $this->requested[0]);
        $this->assertStringContainsString('seasontype=2', $this->requested[0]);
        $this->assertStringContainsString('dates=2026', $this->requested[0]);
    }

    public function testSecondLookupIsServedFromCache(): void
    {
        $client = $this->client([FixtureLoader::raw('2026-w01')]);

        $client->weekSchedule(2026, 1);
        $second = $client->weekSchedule(2026, 1);

        $this->assertCount(16, $second->games());
        $this->assertCount(1, $this->requested, 'A fresh cache entry must not trigger a second request.');
    }

    /* A finished week cannot change, so it stays cached however old it gets. */
    public function testCompletedWeeksAreCachedIndefinitely(): void
    {
        $client = $this->client([FixtureLoader::raw('2025-w03')], 0);

        $client->weekSchedule(2025, 3);
        $client->weekSchedule(2025, 3);

        $this->assertCount(1, $this->requested);
    }

    public function testUnfinishedWeeksAreRefetchedOnceStale(): void
    {
        $client = $this->client([FixtureLoader::raw('2026-w01')], 0);

        $client->weekSchedule(2026, 1);
        $client->weekSchedule(2026, 1);

        $this->assertCount(2, $this->requested, 'An in-progress week must refresh when its TTL lapses.');
    }

    public function testStaleCacheIsServedWhenTheFetchFails(): void
    {
        $client = $this->client([FixtureLoader::raw('2026-w01'), null], 0);

        $client->weekSchedule(2026, 1);
        $afterFailure = $client->weekSchedule(2026, 1);

        $this->assertCount(2, $this->requested);
        $this->assertCount(16, $afterFailure->games(), 'Stale data beats no data.');
    }

    public function testFallsBackToACommittedSnapshotWhenThereIsNoCache(): void
    {
        file_put_contents($this->snapshotDir . '/nfl-2026-w01.json', FixtureLoader::raw('2026-w01'));
        $client = $this->client([null]);

        $schedule = $client->weekSchedule(2026, 1);

        $this->assertCount(16, $schedule->games(), 'Snapshots keep the site working with no outbound network.');
    }

    public function testReturnsAnEmptyScheduleWhenEverythingFails(): void
    {
        $client = $this->client([null]);

        $schedule = $client->weekSchedule(2026, 1);

        $this->assertTrue($schedule->isEmpty());
    }

    /** @dataProvider unusableResponses */
    #[DataProvider('unusableResponses')]
    public function testUnusableResponsesAreNotCached(?string $response): void
    {
        $client = $this->client([$response]);

        $schedule = $client->weekSchedule(2026, 1);

        $this->assertTrue($schedule->isEmpty());
        $this->assertSame([], glob($this->cacheDir . '/*.json') ?: [], 'Garbage must never be written to cache.');
    }

    public static function unusableResponses(): array
    {
        return [
            'connection failed' => [null],
            'empty body' => [''],
            'not json' => ['<html>502 Bad Gateway</html>'],
            'json without events' => ['{"season":{"year":2026}}'],
        ];
    }

    /*
     * Where the data came from has to be inspectable.
     *
     * The ladder is deliberately silent -- a 403 or a blocked host still
     * renders a working page from snapshots. That is right for players and
     * wrong for whoever is maintaining it, who otherwise cannot tell "results
     * haven't happened yet" from "we have not reached ESPN since August".
     * bin/espn-check.php reports these.
     */
    public function testReportsWhereEachLookupCameFrom(): void
    {
        $client = $this->client([FixtureLoader::raw('2026-w01')]);
        $client->weekSchedule(2026, 1);
        $this->assertSame(['nfl-2026-w01' => 'live'], $client->sources());

        $client->weekSchedule(2026, 1);
        $this->assertSame(['nfl-2026-w01' => 'cache'], $client->sources());
    }

    public function testReportsStaleCacheAndSnapshotFallbacks(): void
    {
        $stale = $this->client([FixtureLoader::raw('2026-w01'), null], 0);
        $stale->weekSchedule(2026, 1);
        $stale->weekSchedule(2026, 1);
        $this->assertSame(['nfl-2026-w01' => 'stale-cache'], $stale->sources());

        file_put_contents($this->snapshotDir . '/nfl-2026-w02.json', FixtureLoader::raw('2026-w01'));
        $snapshot = $this->client([null]);
        $snapshot->weekSchedule(2026, 2);
        $this->assertSame(['nfl-2026-w02' => 'snapshot'], $snapshot->sources());

        $nothing = $this->client([null]);
        $nothing->weekSchedule(2026, 9);
        $this->assertSame(['nfl-2026-w09' => 'unavailable'], $nothing->sources());
    }

    /*
     * Regression guard on a bug that hid behind the fallback ladder.
     *
     * ESPN answers 403 to a bare product token such as "LoserPool/1.0" (and to
     * a plain copied browser string). With that user agent every live fetch
     * failed and the site ran entirely on committed snapshots -- which carry no
     * scores, so the results grid would have stayed blank all season while
     * looking completely healthy.
     */
    public function testUserAgentIsAFormEspnAccepts(): void
    {
        $this->assertStringStartsWith('Mozilla/5.0 (compatible;', SeasonConfig::USER_AGENT);
    }

    public function testReadsTheCurrentSeasonAndWeek(): void
    {
        $client = $this->client(['{"season":{"type":2,"year":2026},"week":{"number":5},"events":[]}']);

        $this->assertSame(
            ['year' => 2026, 'seasonType' => 2, 'week' => 5],
            $client->currentSeasonWeek()
        );
    }

    public function testCurrentSeasonWeekIsNullWhenUnavailable(): void
    {
        $this->assertNull($this->client([null])->currentSeasonWeek());
        $this->assertNull($this->client(['{"events":[]}'])->currentSeasonWeek());
    }

    /*
     * "What week is it now" is the one question a stale answer cannot answer.
     *
     * Every other lookup takes the ladder all the way down, because an old
     * schedule still lists the right fixtures. This one must stop at the rungs
     * that are actually current: WeekResolver treats any non-null return as a
     * live reading and follows it, and only null sends it to the calendar. So a
     * remembered "week 1" served while ESPN is unreachable would pin the pool to
     * week 1 for the rest of the season -- reopening a week already played, with
     * the results known -- and the site would look perfectly healthy doing it.
     *
     * That is not hypothetical: the deployed app lost outbound HTTP on
     * 15 September 2026 and was serving exactly this.
     */
    public function testCurrentSeasonWeekRefusesToAnswerFromStaleCache(): void
    {
        $warm = $this->client(['{"season":{"type":2,"year":2026},"week":{"number":1},"events":[]}'], 0);
        $this->assertSame(1, $warm->currentSeasonWeek()['week'], 'a live reading is used');

        /* Same cache directory, no network. The ladder falls to stale cache. */
        $offline = $this->client([null], 0);

        $this->assertNull($offline->currentSeasonWeek());
        $this->assertSame('stale-cache', $offline->sources()['nfl-current']);
    }

    /* Inside the TTL it is a recent reading, not a remembered one, so it counts. */
    public function testCurrentSeasonWeekStillUsesFreshCache(): void
    {
        $warm = $this->client(['{"season":{"type":2,"year":2026},"week":{"number":4},"events":[]}'], 600);
        $warm->currentSeasonWeek();

        $offline = $this->client([null], 600);

        $this->assertSame(4, $offline->currentSeasonWeek()['week']);
        $this->assertSame('cache', $offline->sources()['nfl-current']);
    }
}
