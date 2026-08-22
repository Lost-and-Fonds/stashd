<?php

declare(strict_types=1);

namespace App\Auth;

use Tempest\Database\Database;
use Tempest\Database\Query;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\FormatPattern;
use Tempest\DateTime\Timezone;

final readonly class LoginAttemptLimiter
{
    private const int MAX_ATTEMPTS = 5;

    private const int WINDOW_SECONDS = 900;

    public function __construct(
        private Database $database,
    ) {}

    public function ensureAllowed(string $username, string $clientAddress): void
    {
        $now = DateTime::now(Timezone::UTC);
        $this->prune($now);
        $row = $this->database->fetchFirst(new Query(
            'SELECT attempts FROM login_attempts WHERE "keyHash" = ? AND "expiresAt" > ?',
            [$this->key($username, $clientAddress), $this->sql($now)],
        ));

        $value = is_array($row) ? ($row['attempts'] ?? null) : null;
        $attempts = is_int($value) || is_string($value) ? (int) $value : 0;

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new LoginThrottled('Too many login attempts.');
        }
    }

    public function recordFailure(string $username, string $clientAddress): void
    {
        $now = DateTime::now(Timezone::UTC);
        $this->prune($now);
        $this->database->execute(new Query(
            'INSERT INTO login_attempts ("keyHash", attempts, "expiresAt")
             VALUES (?, 1, ?)
             ON CONFLICT("keyHash") DO UPDATE SET attempts = login_attempts.attempts + 1, "expiresAt" = excluded."expiresAt"',
            [$this->key($username, $clientAddress), $this->sql($now->plusSeconds(self::WINDOW_SECONDS))],
        ));
    }

    public function reset(string $username, string $clientAddress): void
    {
        $this->database->execute(new Query(
            'DELETE FROM login_attempts WHERE "keyHash" = ?',
            [$this->key($username, $clientAddress)],
        ));
    }

    private function prune(DateTime $now): void
    {
        $this->database->execute(new Query('DELETE FROM login_attempts WHERE "expiresAt" <= ?', [$this->sql($now)]));
    }

    private function key(string $username, string $clientAddress): string
    {
        return hash('sha256', strtolower(trim($username)) . "\0" . $clientAddress);
    }

    private function sql(DateTime $dateTime): string
    {
        return $dateTime->format(FormatPattern::SQL_DATE_TIME, Timezone::UTC);
    }
}
