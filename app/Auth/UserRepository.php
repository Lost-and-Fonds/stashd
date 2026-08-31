<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\PrefixedUlidGenerator;
use InvalidArgumentException;
use Tempest\Database\PrimaryKey;
use Tempest\DateTime\DateTime;
use Tempest\DateTime\Timezone;

use function Tempest\Database\query;

final class UserRepository
{
    public function __construct(
        private PrefixedUlidGenerator $ids,
    ) {}

    public function findByUsername(string $username): ?UserRecord
    {
        /** @var UserRecord|null $record */
        $record = UserRecord::select()->where('username', $username)->include('passwordHash')->first();

        return $record;
    }

    public function findById(UserId $id): ?UserRecord
    {
        return UserRecord::findById($id->toPrimaryKey());
    }

    public function createAdmin(string $username, string $passwordHash): UserRecord
    {
        $id = $this->ids->generate('user')->toString();
        $record = new UserRecord(
            username: $username,
            passwordHash: $passwordHash,
            role: UserRole::Admin,
        );
        $record->id = new PrimaryKey($id);
        $now = DateTime::now(Timezone::UTC);
        $record->createdAt ??= $now;
        $record->updatedAt ??= $now;

        try {
            query(UserRecord::class)->insert($record)->execute();
        } catch (\Throwable $exception) {
            if (UserRecord::count()->execute() > 0) {
                throw new InvalidArgumentException('Admin account already exists.', previous: $exception);
            }

            throw $exception;
        }

        return $record;
    }
}
