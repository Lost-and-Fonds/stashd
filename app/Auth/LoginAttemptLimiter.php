<?php

declare(strict_types=1);

namespace App\Auth;

use Tempest\Database\Database;
use Tempest\Database\Query;

final readonly class LoginAttemptLimiter
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private Database $database,
    ) {}

    public function consumeAttempt(string $username, string $clientAddress): void
    {
        $this->pruneExpired();
        $row = $this->database->fetchFirst(new Query(
            <<<'SQL'
            INSERT INTO "login_attempts" ("keyHash", "attempts", "expiresAt")
            VALUES (?, 1, CURRENT_TIMESTAMP + INTERVAL '15 minutes')
            ON CONFLICT ("keyHash") DO UPDATE
            SET "attempts" = CASE
                    WHEN "login_attempts"."expiresAt" <= CURRENT_TIMESTAMP THEN 1
                    WHEN "login_attempts"."attempts" < ? THEN "login_attempts"."attempts" + 1
                    ELSE ?
                END,
                "expiresAt" = CASE
                    WHEN "login_attempts"."expiresAt" <= CURRENT_TIMESTAMP
                        THEN EXCLUDED."expiresAt"
                    ELSE "login_attempts"."expiresAt"
                END
            RETURNING "attempts"
            SQL,
            [$this->key($username, $clientAddress), self::MAX_ATTEMPTS, self::MAX_ATTEMPTS + 1],
        ));

        $value = is_array($row) ? ($row['attempts'] ?? null) : null;
        $attempts = is_int($value) || is_string($value) ? (int) $value : 0;

        if ($attempts > self::MAX_ATTEMPTS || $row === null) {
            throw new LoginThrottled('Too many login attempts.');
        }
    }

    public function reset(string $username, string $clientAddress): void
    {
        $this->database->execute(new Query(
            'DELETE FROM login_attempts WHERE "keyHash" = ?',
            [$this->key($username, $clientAddress)],
        ));
    }

    private function key(string $username, string $clientAddress): string
    {
        return hash('sha256', strtolower(trim($username)) . "\0" . $clientAddress);
    }

    private function pruneExpired(): void
    {
        $this->database->execute(new Query('DELETE FROM "login_attempts" WHERE "expiresAt" <= CURRENT_TIMESTAMP'));
    }
}
