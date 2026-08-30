<?php

namespace LoserPool\Storage;

use PDO;
use PDOException;

/*
 * SQLite implementation, via PDO.
 *
 * This exists for two reasons. It is the first storage code the project can
 * actually test -- it runs in memory, so the register/pick/overwrite paths get
 * exercised instead of being assumed -- and it removes the database server
 * from deployment, which is what makes the app portable off the current host.
 *
 * The schema is stricter than the MySQL one it replaces. The original had no
 * NOT NULL, no keys and no uniqueness, and relied on the application to check
 * "does a pick already exist for this week" before choosing UPDATE or INSERT.
 * That check is a race: two submissions in the same moment both see no pick
 * and both insert, and the player quietly has two picks for one week. Here a
 * unique key on (username, week_number) makes that impossible, and the upsert
 * is a single statement.
 */
final class SqliteStore implements PoolStore
{
    private PDO $pdo;
    private string $userTable;
    private string $picksTable;
    private string $buybackTable;

    public function __construct(PDO $pdo, string $seasonSuffix)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->userTable = 'Users_' . $seasonSuffix;
        $this->picksTable = 'Picks_' . $seasonSuffix;
        $this->buybackTable = 'Buybacks_' . $seasonSuffix;
        $this->createTables();
        $this->migratePlaintextPins();
        $this->repairForeignKeysLeftDangling();
    }

    /* Opens (and creates) a database file, or ':memory:' for tests. */
    public static function open(string $path, string $seasonSuffix): self
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        /* Enforce the foreign key below; SQLite ignores it otherwise. */
        $pdo->exec('PRAGMA foreign_keys = ON');
        /* Let a reader continue while a writer commits -- picks arrive in bursts. */
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        return new self($pdo, $seasonSuffix);
    }

    private function createTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->userTable} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                pin_hash TEXT NOT NULL
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->picksTable} (
                pickid INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL COLLATE NOCASE,
                week_number INTEGER NOT NULL,
                pick TEXT NOT NULL,
                UNIQUE (username, week_number),
                FOREIGN KEY (username) REFERENCES {$this->userTable}(username)
            )"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->buybackTable} (
                username TEXT PRIMARY KEY COLLATE NOCASE,
                FOREIGN KEY (username) REFERENCES {$this->userTable}(username)
            )"
        );
    }

    /*
     * Earlier versions stored the PIN itself, as an integer.
     *
     * Rebuilds such a table around a hash, re-hashing any rows already there.
     * SQLite cannot change a column in place, so the table is recreated and
     * copied -- the standard pattern, and cheap at this size.
     */
    private function migratePlaintextPins(): void
    {
        $columns = $this->pdo->query("PRAGMA table_info({$this->userTable})")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');

        if (!in_array('pin', $names, true) || in_array('pin_hash', $names, true)) {
            return;
        }

        $existing = $this->pdo->query("SELECT * FROM {$this->userTable}")->fetchAll(PDO::FETCH_ASSOC);

        /*
         * legacy_alter_table, for the one thing it is still good for.
         *
         * Since SQLite 3.25 a RENAME rewrites references to that table inside
         * every OTHER table's foreign keys. So renaming the users table out of
         * the way silently repointed the picks and buy-back tables at
         * Users_<season>_old, and the DROP three lines below then took that
         * table away -- leaving both of them referencing something that does
         * not exist. With foreign_keys ON, which this store sets, every insert
         * of a pick failed from then on.
         *
         * The pragma restores the older behaviour of leaving other tables
         * alone, which is what this migration wants: nothing outside the users
         * table is being changed.
         */
        $this->pdo->exec('PRAGMA legacy_alter_table = ON');
        $this->pdo->exec("ALTER TABLE {$this->userTable} RENAME TO {$this->userTable}_old");
        $this->pdo->exec('PRAGMA legacy_alter_table = OFF');
        $this->createTables();

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->userTable} (id, name, email, username, pin_hash) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($existing as $row) {
            $insert->execute([
                $row['id'],
                $row['name'],
                $row['email'],
                $row['username'],
                password_hash((string) $row['pin'], PASSWORD_DEFAULT),
            ]);
        }

        $this->pdo->exec("DROP TABLE {$this->userTable}_old");
    }

    /*
     * Rebuilds any table whose foreign key points at a table that is gone.
     *
     * Databases that went through the PIN migration before the pragma above
     * was added are already damaged, and they are the ones with real picks in
     * them. This finds that specific breakage -- a reference to
     * Users_<season>_old, which is never a table that should exist once the
     * migration has finished -- and rebuilds the table around the correct
     * foreign key, keeping every row.
     *
     * Cheap to check and does nothing on a healthy database, so it runs on
     * open rather than as a migration somebody has to remember.
     */
    private function repairForeignKeysLeftDangling(): void
    {
        $stale = $this->userTable . '_old';

        foreach ([$this->picksTable, $this->buybackTable] as $table) {
            $sql = $this->pdo
                ->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($table))
                ->fetchColumn();

            if ($sql === false || strpos((string) $sql, $stale) === false) {
                continue;
            }

            $rows = $this->pdo->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);

            /*
             * Off for the rebuild: the table being dropped is referenced by
             * nothing, but the drop and recreate is a schema change and SQLite
             * asks for foreign keys to be off during those.
             */
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
            $this->pdo->exec("DROP TABLE {$table}");
            $this->createTables();

            if ($rows !== []) {
                $columns = array_keys($rows[0]);
                $insert = $this->pdo->prepare(
                    "INSERT INTO {$table} (" . implode(', ', $columns) . ")"
                    . " VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")"
                );
                foreach ($rows as $row) {
                    $insert->execute(array_values($row));
                }
            }

            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    public function buybacks(): array
    {
        $rows = $this->pdo->query("SELECT username FROM {$this->buybackTable}")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function grantBuyback(string $username): int
    {
        if (!$this->userExists($username)) {
            return self::NO_SUCH_USER;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->buybackTable} (username) VALUES (?)
             ON CONFLICT (username) DO NOTHING"
        );
        $stmt->execute([$username]);
        return self::OK;
    }

    public function allUsernames(): array
    {
        $rows = $this->pdo->query("SELECT username FROM {$this->userTable}")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function userExists(string $username): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->userTable} WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        return $stmt->fetchColumn() !== false;
    }

    public function verifyPin(string $username, string $pin): bool
    {
        $stmt = $this->pdo->prepare("SELECT pin_hash FROM {$this->userTable} WHERE username = ?");
        $stmt->execute([$username]);
        $stored = $stmt->fetchColumn();

        /*
         * Unknown user: fail closed. A dummy verify keeps the work done here
         * roughly constant either way, so response time does not quietly
         * report whether a username exists.
         */
        if ($stored === false) {
            password_verify($pin, '$2y$10$usesomesillystringforsalt0000000000000000000000000000000');
            return false;
        }

        return password_verify($pin, (string) $stored);
    }

    public function addUser(string $name, string $email, string $username, string $pin): int
    {
        if ($this->userExists($username)) {
            return self::USER_EXISTS;
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->userTable} (name, email, username, pin_hash) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$name, $email, $username, password_hash($pin, PASSWORD_DEFAULT)]);
            return self::OK;
        } catch (PDOException $e) {
            /* Loses a race with a simultaneous registration of the same name. */
            return self::ERROR;
        }
    }

    public function emailFor(string $username): ?string
    {
        $stmt = $this->pdo->prepare("SELECT email FROM {$this->userTable} WHERE username = ?");
        $stmt->execute([$username]);
        $email = $stmt->fetchColumn();

        return $email === false ? null : (string) $email;
    }

    public function setPin(string $username, string $pin): int
    {
        if (!$this->userExists($username)) {
            return self::NO_SUCH_USER;
        }

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->userTable} SET pin_hash = ? WHERE username = ?"
            );
            $stmt->execute([password_hash($pin, PASSWORD_DEFAULT), $username]);
            return self::OK;
        } catch (PDOException $e) {
            return self::ERROR;
        }
    }

    public function picksFor(string $username): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT week_number, pick FROM {$this->picksTable} WHERE username = ?"
        );
        $stmt->execute([$username]);

        $picks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $picks[(int) $row['week_number']] = (string) $row['pick'];
        }
        return $picks;
    }

    public function allPicks(): array
    {
        $rows = $this->pdo
            ->query("SELECT username, week_number, pick FROM {$this->picksTable}")
            ->fetchAll(PDO::FETCH_ASSOC);

        $picks = [];
        foreach ($rows as $row) {
            $picks[(string) $row['username']][(int) $row['week_number']] = (string) $row['pick'];
        }
        return $picks;
    }

    public function savePick(string $username, string $team, int $week): int
    {
        if (!$this->userExists($username)) {
            return self::NO_SUCH_USER;
        }

        try {
            /*
             * One statement, so changing a pick cannot race with itself. The
             * old read-then-decide approach could insert two picks for a week.
             */
            $stmt = $this->pdo->prepare(
                "INSERT INTO {$this->picksTable} (username, week_number, pick)
                 VALUES (?, ?, ?)
                 ON CONFLICT (username, week_number) DO UPDATE SET pick = excluded.pick"
            );
            $stmt->execute([$username, $week, $team]);
            return self::OK;
        } catch (PDOException $e) {
            return self::ERROR;
        }
    }
}
