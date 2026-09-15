<?php

namespace LoserPool\Nfl;

use DateTimeZone;
use LoserPool\Pool\SeasonConfig;

/*
 * Reads NFL schedules and results from ESPN's public scoreboard API.
 *
 * The pool used to keep this data as hand-typed PHP arrays that somebody
 * updated every week. This class replaces that chore, but it inherits the
 * chore's one virtue: the old arrays could not fail at request time. So every
 * lookup here walks a degradation ladder and never throws:
 *
 *   fresh cache -> live fetch -> stale cache -> bundled snapshot -> null
 *
 * A null payload becomes an empty Schedule, which the rules treat as "nothing
 * known yet": no teams blocked, no results coloured. The site stays up.
 *
 * We cannot verify whether the production host permits outbound HTTP, so the
 * offline path is a first-class case rather than an error branch.
 */
final class EspnClient implements ScheduleSource
{
    private const BASE_URL = 'https://site.api.espn.com/apis/site/v2/sports/football/nfl/scoreboard';
    private const REGULAR_SEASON = 2;


    private string $cacheDir;
    private ?string $snapshotDir;
    private DateTimeZone $timezone;
    private int $ttlSeconds;
    private int $timeoutSeconds;

    /** @var callable(string):?string */
    private $httpGet;

    /* Where each lookup's data actually came from, for bin/espn-check.php. */
    private array $sources = [];
    private ?int $lastHttpStatus = null;

    /**
     * @param callable(string):?string|null $httpGet Injectable transport, so tests
     *        can exercise the cache ladder without a network.
     */
    public function __construct(
        string $cacheDir,
        DateTimeZone $timezone,
        ?string $snapshotDir = null,
        int $ttlSeconds = 600,
        int $timeoutSeconds = 5,
        ?callable $httpGet = null
    ) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->snapshotDir = $snapshotDir === null ? null : rtrim($snapshotDir, '/');
        $this->timezone = $timezone;
        $this->ttlSeconds = $ttlSeconds;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->httpGet = $httpGet ?? [$this, 'fetchOverHttp'];
    }

    public function weekSchedule(int $season, int $week): Schedule
    {
        $url = self::BASE_URL . '?' . http_build_query([
            'dates' => $season,
            'seasontype' => self::REGULAR_SEASON,
            'week' => $week,
        ]);

        $payload = $this->payload(sprintf('nfl-%d-w%02d', $season, $week), $url);
        return Schedule::fromEspnPayload($payload, $this->timezone);
    }

    /*
     * Which week the NFL is in right now, or null if we cannot currently say.
     *
     * This is the one lookup that does not take the degradation ladder's lower
     * rungs. Everywhere else, old data is better than none: a stale week 3
     * schedule still lists the right fixtures. Here it is the opposite, because
     * the question is "what week is it *now*" and a remembered answer to that
     * is not a worse answer, it is last week's answer.
     *
     * It matters because the caller cannot tell the difference. WeekResolver
     * reads a non-null return as a live reading and follows it; only null sends
     * it to the calendar, which needs no network and is right every day of the
     * season. So when ESPN is unreachable, a cached "week 1" would pin the pool
     * to week 1 for the rest of the year -- picks for a week already played,
     * reopened, with the results known -- while the site looked healthy.
     *
     * Fresh cache is still fine: it is inside the TTL, so it is a recent
     * reading rather than a remembered one.
     */
    public function currentSeasonWeek(): ?array
    {
        /* The unparameterised scoreboard reports whatever the NFL is doing now. */
        $payload = $this->payload('nfl-current', self::BASE_URL, false);
        if ($payload === null || !isset($payload['season']['type'], $payload['week']['number'])) {
            return null;
        }
        if (!in_array($this->sources['nfl-current'] ?? '', ['live', 'cache'], true)) {
            return null; /* stale cache or snapshot: too old to answer this question */
        }

        return [
            'year' => (int) ($payload['season']['year'] ?? 0),
            'seasonType' => (int) $payload['season']['type'],
            'week' => (int) $payload['week']['number'],
        ];
    }

    /*
     * The degradation ladder. $cacheable=false for endpoints whose contents are
     * inherently transient (the "what week is it" probe is never final).
     */
    private function payload(string $key, string $url, bool $canBeFinal = true): ?array
    {
        $cached = $this->readCache($key);
        if ($cached !== null && $this->isFresh($cached, $canBeFinal)) {
            $this->sources[$key] = 'cache';
            return $cached['payload'];
        }

        $raw = call_user_func($this->httpGet, $url);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['events'])) {
                $this->writeCache($key, $decoded, $canBeFinal && $this->allEventsComplete($decoded));
                $this->sources[$key] = 'live';
                return $decoded;
            }
        }

        /* Live fetch failed. Anything we already had beats showing nothing. */
        if ($cached !== null) {
            $this->sources[$key] = 'stale-cache';
            return $cached['payload'];
        }

        $snapshot = $this->readSnapshot($key);
        $this->sources[$key] = $snapshot === null ? 'unavailable' : 'snapshot';
        return $snapshot;
    }

    private function isFresh(array $envelope, bool $canBeFinal): bool
    {
        if ($canBeFinal && !empty($envelope['final'])) {
            return true; /* a finished week never changes again */
        }
        $age = time() - (int) ($envelope['fetched_at'] ?? 0);
        return $age >= 0 && $age < $this->ttlSeconds;
    }

    private function allEventsComplete(array $payload): bool
    {
        if (empty($payload['events'])) {
            return false;
        }
        foreach ($payload['events'] as $event) {
            if (empty($event['competitions'][0]['status']['type']['completed'])) {
                return false;
            }
        }
        return true;
    }

    private function readCache(string $key): ?array
    {
        $file = $this->cacheDir . '/' . $key . '.json';
        if (!is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded) || !isset($decoded['payload']) || !is_array($decoded['payload'])) {
            return null;
        }
        return $decoded;
    }

    /*
     * Best-effort: a read-only or full disk must not take the site down, so
     * every failure here is swallowed. Worst case we re-fetch next request.
     */
    private function writeCache(string $key, array $payload, bool $final): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            return;
        }

        $envelope = json_encode([
            'fetched_at' => time(),
            'final' => $final,
            'payload' => $payload,
        ]);
        if ($envelope === false) {
            return;
        }

        /* Write-then-rename so a concurrent reader never sees a half file. */
        $target = $this->cacheDir . '/' . $key . '.json';
        $temp = $target . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $envelope) !== false) {
            if (!@rename($temp, $target)) {
                @unlink($temp);
            }
        }
    }

    /*
     * Snapshots are committed to the repo and hold a bare payload (no envelope).
     * They exist so the site still works on a host with no outbound network.
     */
    private function readSnapshot(string $key): ?array
    {
        if ($this->snapshotDir === null) {
            return null;
        }
        $file = $this->snapshotDir . '/' . $key . '.json';
        if (!is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /*
     * How the most recent lookups were satisfied, keyed by cache key:
     * live, cache, stale-cache, snapshot or unavailable.
     *
     * @return array<string,string>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    public function lastHttpStatus(): ?int
    {
        return $this->lastHttpStatus;
    }

    private function fetchOverHttp(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => SeasonConfig::USER_AGENT,
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);
            $this->lastHttpStatus = $status;
            return ($body !== false && $status >= 200 && $status < 300) ? (string) $body : null;
        }

        $context = stream_context_create(['http' => [
            'timeout' => $this->timeoutSeconds,
            'header' => "User-Agent: " . SeasonConfig::USER_AGENT . "\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : (string) $body;
    }
}
