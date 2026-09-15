<?php

namespace LoserPool\Nfl;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/*
 * One week of NFL games.
 *
 * All ESPN-specific parsing lives here and nowhere else. Everything downstream
 * (the pool rules, the handlers) works against Game objects, so if ESPN ever
 * changes its payload shape this is the only file that needs to move.
 *
 * Parsing is deliberately forgiving: a malformed or partial payload yields an
 * empty (or smaller) Schedule rather than an exception. A rendering page that
 * shows no results is recoverable; a fatal error on the pool's front page
 * during game week is not.
 */
final class Schedule
{
    /** @var Game[] */
    private array $games;

    /** @param Game[] $games */
    public function __construct(array $games)
    {
        $this->games = $games;
    }

    public static function emptySchedule(): self
    {
        return new self([]);
    }

    /*
     * Build from a decoded ESPN scoreboard response.
     * $timezone is the pool's timezone; kickoffs are converted into it.
     */
    public static function fromEspnPayload(?array $payload, DateTimeZone $timezone): self
    {
        if ($payload === null || !isset($payload['events']) || !is_array($payload['events'])) {
            return self::emptySchedule();
        }

        $games = [];
        foreach ($payload['events'] as $event) {
            $game = self::parseEvent($event, $timezone);
            if ($game !== null) {
                $games[] = $game;
            }
        }

        return new self($games);
    }

    private static function parseEvent($event, DateTimeZone $timezone): ?Game
    {
        if (!is_array($event) || !isset($event['date'], $event['competitions'][0])) {
            return null;
        }

        try {
            $kickoff = (new DateTimeImmutable($event['date']))->setTimezone($timezone);
        } catch (Exception $e) {
            return null;
        }

        $competition = $event['competitions'][0];
        if (!isset($competition['competitors']) || !is_array($competition['competitors'])) {
            return null;
        }

        $teams = [];
        $winner = null;
        foreach ($competition['competitors'] as $competitor) {
            if (!isset($competitor['team']['displayName'])) {
                continue;
            }
            $name = $competitor['team']['displayName'];
            $teams[] = $name;
            if (isset($competitor['winner']) && $competitor['winner'] === true) {
                $winner = $name;
            }
        }

        if ($teams === []) {
            return null;
        }

        $completed = isset($competition['status']['type']['completed'])
            && $competition['status']['type']['completed'] === true;

        /* Only trust a winner on a finished game; ESPN can carry stale flags. */
        return new Game($teams, $kickoff, $completed, $completed ? $winner : null);
    }

    /** @return Game[] */
    public function games(): array
    {
        return $this->games;
    }

    public function isEmpty(): bool
    {
        return $this->games === [];
    }

    /*
     * Has every game this week finished?
     *
     * An empty schedule is not complete. "No games known" and "all games
     * played" are the same shape -- nothing left outstanding -- and only one of
     * them means the week is settled. Treating a failed schedule load as a
     * finished week would eliminate everyone who had not picked in it.
     */
    public function isComplete(): bool
    {
        if ($this->games === []) {
            return false;
        }

        foreach ($this->games as $game) {
            if (!$game->isCompleted()) {
                return false;
            }
        }

        return true;
    }

    /* Every team with a game this week. Teams on bye are simply absent. */
    public function teamsPlaying(): array
    {
        $teams = [];
        foreach ($this->games as $game) {
            foreach ($game->teams() as $team) {
                $teams[$team] = true;
            }
        }
        return array_keys($teams);
    }

    public function gameFor(string $team): ?Game
    {
        foreach ($this->games as $game) {
            if ($game->involves($team)) {
                return $game;
            }
        }
        return null;
    }
}
