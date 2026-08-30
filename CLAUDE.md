# Working on this repo

A season-long NFL "loser pool": each week players pick one team they expect to
lose. PHP 8 + HTMX + SQLite, no build step, no framework. Deployed as a single
container on Fly.io.

## The rules are the product

Get these wrong and the pool breaks in ways that are invisible until the season
is underway. They are enforced in `src/Pool/`.

- Pick one team per week that will **lose**. No repeat picks all season.
- **A tie eliminates.** A team that tied did not lose. ESPN encodes a tie as
  `winner: false` on *both* competitors, so the obvious check — "was this team
  the winner?" — scores a tie as a successful pick.
- **Teams playing Wednesday, Thursday, Friday or Saturday cannot be picked** —
  their games start before the deadline. The list is weekdays, not "anything
  before Sunday", because the 2026 opener is a *Wednesday* game and a literal
  "no Thursday games" check misses it.
- **Blocking Saturday costs the pool week 18, deliberately.** 2026 week 18 is
  played entirely on Saturday, so every one of the 32 teams is unpickable and
  nobody can submit a week 18 pick through the site. The commissioner chose
  this over leaving a hole where a player could watch a Saturday team lose and
  then pick it before the Saturday night deadline. Week 18 is settled off-site.
  Do not "fix" it by dropping `'Sat'` from `BLOCKED_KICKOFF_DAYS`.
- Bye teams cannot be picked.
- Picks are due **11:59 p.m. Central Saturday**; the lock itself falls at 00:00
  Sunday and lifts 00:00 Tuesday, so the published time is a minute early on
  purpose. Other players' current week picks stay hidden until lock.
- **Buy back in: week 1 only.** Recorded with `bin/buyback.php`, never inferred
  — an eliminated player can still submit picks, so continued play proves
  nothing.
- **Registration closes when week 1 locks** and does not reopen. Use
  `lp_registration_is_open()`, not `lp_picks_are_locked()`: the latter is true
  on Sunday and Monday of *every* week and false again on Tuesday, so it would
  reopen the pool to entrants for five days out of seven all season.
  `bin/register.php` is the commissioner's way past the gate.
- **Final four or fewer may split the pot**, unanimously. Commissioner-run;
  the site only reports who is left.

## Conventions

- **No AI signatures.** No `Co-Authored-By`, no "Generated with", in commits or
  PR bodies.
- **PR descriptions are documentation**, written for whoever reads them next.
  Never address a person, never reference the conversation that produced the
  change. State what changed, why, and what was rejected.
- **Target PHP 7.4 syntax.** The original code implies it and CI enforces it. No
  constructor promotion, `match`, enums, readonly, or nullsafe operators.
- **Composer is dev-only.** The deployed app must never require
  `composer install`; `src/autoload.php` is hand-rolled for that reason.
- Season rollover is one file: `src/Pool/SeasonConfig.php`.

## Layout

Only `htdocs/` is served. Everything else sits beside it so it is unreachable
over HTTP by structure, not by an Apache rule — a rule already proved fragile
once by blocking a live endpoint.

```
htdocs/     docroot: endpoints and assets only
src/        Nfl/ (ESPN parsing) Pool/ (rules) Storage/ (PoolStore) Handlers/
data/       committed schedule snapshots — the offline fallback
tests/      Unit/ and Integration/
bin/        CLI tools
```

## Things that have already gone wrong here

Each of these passed a green test suite.

- **ESPN 403s some user agents.** A bare product token is rejected; the
  `Mozilla/5.0 (compatible; ...)` form in `SeasonConfig::USER_AGENT` is not.
  The fallback ladder hides the failure — the site serves fine from snapshots
  while results never update. `bin/espn-check.php` and `/health.php` exist to
  surface it. Do not "tidy" that user agent.
- **A dead-code sweep deleted a live function.** The reference check searched
  only for symbols intended for removal, so it confirmed an assumption instead
  of testing it. `/teams/all.php` fatalled — nobody could pick — with CI green.
  After removing anything, grep for what remains, and run the app.
- **Day-of-year season guards invert in January.** `date('z') > 245` silently
  stopped applying in weeks 17–18. Use `lp_season_in_progress()`.
- **Local file modes leak into the container.** `COPY` preserves them, and a
  restrictive umask produces files Apache cannot read — a blanket 403 that
  looks like a config error. The Dockerfile normalises with `chmod a+rX`.
- **A volume's root is owned by root at mount time**, so a build-time `chown`
  of `/data` is shadowed. The entrypoint prepares it at container start.
- **Verify by grepping for failure, not success.** A check that grepped for a
  username matched it inside a PHP stack trace and reported a broken write path
  as working.

## Front end

`htdocs/teampicker.js` upgrades the team `<select>` into a listbox that can
show club crests, which a native select cannot do. It is progressive
enhancement: the select stays in the DOM as the form control and the no-JS
fallback, and the script re-runs on `htmx:afterSwap` because htmx replaces the
select whenever the chosen username changes. Keep the native select updated on
choice, or the form posts nothing.

Crests and club colours are committed under `htdocs/img/teams/` and mapped in
`Nfl\Teams`, so neither depends on a network call. Club colour is decorative
only: several clubs share a near-identical primary, so it must never be the
sole carrier of meaning.

Unavailable teams are listed and disabled, never omitted. A team missing from
the list is indistinguishable from one that never existed, and the search box
makes it worse: hunting for a team you already used returns "No teams match",
which reads as a bug. `Rules::unavailabilityReasons()` supplies the wording.

Do not put `overflow: hidden` on a card containing the picker. The popup is
absolutely positioned and gets clipped at the card boundary; the accent bar on
`.card-primary` carries its own border-radius for this reason.

## Testing

`vendor/bin/phpunit`. Rules and storage are pure or in-memory, so the suite runs
in seconds and needs no network or server.

The clock is injectable (`lp_clock()`), the schedule source is injectable
(`lp_schedule_source()`), and the store is injectable (`lp_store()`). Use them —
several rules are day-of-week dependent and would otherwise only be testable on
the days they concern.

`tests/SeasonBuilder.php` builds synthetic weeks so a whole season can be
played through the real handlers, with named teams losing on cue. The recorded
ESPN fixtures cannot do this: their results are whatever actually happened, so
they cannot express "this player's team loses in week 3".

Mutation testing is worth the ten minutes. Break a rule on purpose, run the
suite, and confirm it fails. Doing this on the standings found a rule with no
coverage at all: removing the `break` that stops at a player's first failed
pick changed nothing, because every test had only one failure in it. A suite
that passes against deliberately broken code is measuring nothing.

Tests alone have not been sufficient here. Both of the worst defects above were
outages that a green suite missed. Run the app.
