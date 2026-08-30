# Loser Pick'em

A season-long NFL pool: each week you pick one team you expect to **lose**.

Live: <https://loser-pool-2026.fly.dev>

## Rules

- Pick one team per week that you think will lose. **No repeat picks** all season.
- **A tie eliminates you.** You must pick a team that loses, and a team that
  ties did not lose.
- **Teams playing Wednesday, Thursday or Friday cannot be picked** — their games
  start before the deadline. **Saturday games are pickable.**
- Teams on a bye cannot be picked.
- Picks are open Tuesday through Saturday, and lock Sunday and Monday.
- Other players' picks for the current week stay hidden until the slate starts.
- **Buy back in: week 1 only.** Recorded with `bin/buyback.php`, since an
  eliminated player can still submit picks — continuing to play does not show
  that anyone bought back.
- **Registration closes when week one locks**, and does not reopen. Nobody
  joins a pool that is already running: an entrant arriving in week three would
  carry no losses and stand level with a player who has survived three weeks.
  `bin/register.php` is the way past it, for the one case that recurs -- a
  player who paid on time and never entered themselves.
- A forgotten PIN is a message to the commissioner and `bin/reset-pin.php`.
  PINs are bcrypt hashes, so there is nothing to look up and send back; the
  tool prints the address the player registered with, so the person asking can
  be checked against the person who registered.
- **Final four or fewer may split the pot, by unanimous agreement.**

The pot split is run by the commissioner and is not enforced in code.

## How it works

PHP 8 with HTMX on the front end and SQLite for storage. No build step, no
framework, and no database server to run.

Schedule, byes, kickoff times and results come from ESPN's public scoreboard
API. Earlier versions kept that data as hand-typed PHP arrays that somebody
edited every week; now the only thing edited between seasons is one config
file.

```
htdocs/          the docroot -- endpoints and assets, nothing else
  index.html     the page
  week.php       current week (HTMX)
  health.php     is this deployment actually working?
  picks/ users/ teams/   HTMX endpoints
src/             application code, not web-reachable
  Nfl/           ESPN payloads -> Schedule/Game value objects
  Pool/          the rules, the season config, week resolution
  Storage/       PoolStore interface + the SQLite implementation
  Handlers/      request handling, HTML rendering
data/snapshots/  committed schedule data: the offline fallback
tests/           unit + integration suites
bin/             CLI tools
```

Application code lives beside the docroot rather than inside it, so it is
unreachable over HTTP by layout rather than by a rule that could be
misconfigured.

### Reading the season from ESPN

Lookups walk a ladder and never throw: **fresh cache → live fetch → stale
cache → committed snapshot**. A total failure yields an empty schedule, which
blocks no teams and colours no results, so the page still renders.

Only live data carries scores, so a deployment that cannot reach ESPN will
serve a working site whose results never update. That failure is silent by
design, which is why `/health.php` exists to report it.

## Running it

Requires PHP 8 with `pdo_sqlite`. No Composer needed to run — that is dev-only.

```
php -S localhost:8000 -t htdocs
```

Tests:

```
composer install
vendor/bin/phpunit
```

CLI tools:

```
php bin/pool-status.php all      # every week's blocked teams
php bin/espn-check.php           # can this machine reach ESPN?
php bin/refresh-snapshots.php    # re-record the offline schedule data
php bin/buyback.php <username>   # record a week 1 buy-back
php bin/buyback.php --list       # who has bought back
```

## Deploying

Pushing to `master` deploys to Fly.io via GitHub Actions, gated on the test
suite, and then asserts the deployed site reports healthy. Needs a
`FLY_API_TOKEN` repository secret.

Manually:

```
flyctl deploy --remote-only
```

One machine with SQLite on a mounted volume. **SQLite pins this to a single
machine** - a volume attaches to one machine, so do not scale past one.

### Knowing when it is broken

The failure that matters here is not the site going down. It is ESPN refusing
the request: the site falls back to committed snapshots, serves every page
correctly, and results silently stop updating for the rest of the season. That
has happened before, and nobody noticed, because nothing looked wrong.

`/health.php` detects it. `.github/workflows/health.yml` reads it once a day and
fails the job if it does not say `Status OK`, which mails whoever owns the
repository.

`health.php` returns 200 even when it reports a problem, on purpose. Returning
503 would make Fly's own check mark the machine unhealthy and stop routing to
it, taking the site down over a degradation it is designed to survive.

### Backups

Two layers, because they fail differently.

**Daily copy on the volume.** `.github/workflows/backup.yml` runs
`bin/backup.php` at 09:00 UTC, which writes `/data/backups/pool-<timestamp>.sqlite`
and keeps the last 14. It uses `VACUUM INTO` rather than a file copy: Apache is
serving the database while this runs, and copying a live SQLite file can catch
it mid-write and produce something that will not open. The tool reopens what it
wrote and counts rows before rotating anything away, and the workflow fails if
that line is missing -- a backup job that goes quietly wrong is worse than none,
because it gets believed.

This is the layer that survives a mistake: a bad `bin/` command, a delete that
took more than it should.

**Fly volume snapshots.** Scheduled, 30 day retention. This is the layer that
survives losing the volume, which the daily copy cannot, because the daily copy
is on the volume.

```
fly volumes snapshots list vol_vly92gyq5n7owp84    # what exists
fly volumes snapshots create vol_vly92gyq5n7owp84  # one now, before something risky
```

Backups are deliberately not workflow artifacts. This repository is public and
the database holds every player's email address and PIN hash.

**To restore a daily copy**, over the live database:

```
fly ssh console --app loser-pool-2026 -C "php bin/backup.php --list"
fly ssh console --app loser-pool-2026
  cp /data/backups/pool-<timestamp>.sqlite /data/pool.sqlite
  chown www-data:www-data /data/pool.sqlite
```

**To restore a snapshot**, `fly volumes fork <snapshot-id>` creates a new volume
from it, which then has to be attached in place of the current one. That is a
bigger operation and loses anything written since; reach for the daily copy
first.

**To pull one off Fly entirely**, before something risky:

```
fly ssh sftp get /data/pool.sqlite ./pool-$(date +%F).sqlite --app loser-pool-2026
```

## Rolling over to a new season

Edit `src/Pool/SeasonConfig.php`: `YEAR`, `TABLE_SUFFIX` (the last two digits
of the year) and `WEEK_ONE_START` (the Tuesday before the opener). Then
`php bin/refresh-snapshots.php`.

Each season gets its own tables, created on first request, so a rollover
starts empty and everyone re-registers. Tests guard the mistakes that would
otherwise fail silently — bumping the year without the table suffix points the
new season at last season's data.

## History

Originally by Adam Shufelt as PHP + MySQL, at
[ashufelt/LoserPoolWebsite](https://github.com/ashufelt/LoserPoolWebsite).
