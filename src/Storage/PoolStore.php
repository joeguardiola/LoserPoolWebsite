<?php

namespace LoserPool\Storage;

/*
 * Everything the pool needs to persist: six operations over users and picks.
 *
 * Extracted so the storage engine is a deployment choice rather than a
 * rewrite. MySQL is what the current host runs; SQLite is what makes the
 * layer testable at all, since it runs in memory in a test and needs no
 * server to deploy.
 *
 * Note there is no pin accessor, and PINs are strings.
 *
 * Reading a PIN out and comparing it at the call site is how the old code
 * worked, and it is a sharp edge: PHP's loose comparison makes `0 != null`
 * false, so a "no such user" sentinel of null would authenticate a
 * nonexistent user who guessed the PIN 0000. The check belongs behind the
 * interface, where it can only be done one way -- and where the stored value
 * can be a hash rather than the PIN itself.
 *
 * They are strings because they are digit sequences, not quantities: as
 * integers, "0123" and "123" are the same PIN.
 */
interface PoolStore
{
    public const OK = 0;
    public const ERROR = 1;
    public const NO_SUCH_USER = 2;
    public const USER_EXISTS = 4;

    /** @return string[] every registered username */
    public function allUsernames(): array;

    public function userExists(string $username): bool;

    public function verifyPin(string $username, string $pin): bool;

    /** @return int one of OK, ERROR, USER_EXISTS */
    public function addUser(string $name, string $email, string $username, string $pin): int;

    /*
     * The address a player registered with, or null if there is no such
     * player. Used only to check a PIN reset request against the registration;
     * it is never rendered, because players were promised their address is not
     * shown to anyone else.
     */
    public function emailFor(string $username): ?string;

    /*
     * Replaces a player's PIN.
     *
     * A reset rather than a reminder: PINs are stored as bcrypt hashes, so
     * there is nothing to remind anyone of.
     *
     * @return int one of OK, ERROR, NO_SUCH_USER
     */
    public function setPin(string $username, string $pin): int;

    /** @return array<int,string> week number => team picked */
    public function picksFor(string $username): array;

    /*
     * Every player's picks in one query.
     *
     * The standings table renders every player against every week, and asking
     * per player made a page load run one query per entrant. Cheap against a
     * local file, but it is the pattern that gets copied forward.
     *
     * @return array<string,array<int,string>> username => (week => team)
     */
    public function allPicks(): array;

    /*
     * Records a pick, replacing any existing pick for that week.
     *
     * @return int one of OK, ERROR, NO_SUCH_USER
     */
    public function savePick(string $username, string $team, int $week): int;

    /*
     * Players who bought back in after week one.
     *
     * Recorded rather than inferred: an eliminated player can still submit
     * picks, so continuing to play does not prove anyone bought back.
     *
     * @return string[]
     */
    public function buybacks(): array;

    /** @return int one of OK, NO_SUCH_USER */
    public function grantBuyback(string $username): int;

    /*
     * Reminders already sent for a week, so a job that runs hourly mails each
     * player at most once. Recorded per player rather than per week: a run
     * that fails partway must resume, not restart.
     *
     * @return string[] usernames
     */
    public function remindersSent(int $week): array;

    /** @return int one of OK, NO_SUCH_USER */
    public function recordReminder(string $username, int $week, string $sentAt): int;
}
