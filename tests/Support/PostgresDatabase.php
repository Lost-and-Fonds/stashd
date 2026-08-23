<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Tempest\Database\Builder\QueryBuilders\BuildsQuery;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Query;
use Tempest\Support\Str\ImmutableString;
use UnitEnum;

final class PostgresDatabase implements Database
{
    public DatabaseDialect $dialect = DatabaseDialect::POSTGRESQL;

    public string|UnitEnum|null $tag = null;

    public function __construct(private PDO $pdo) {}

    /** @param BuildsQuery<object>|Query $query */
    public function execute(BuildsQuery|Query $query): void
    {
        if ($query instanceof BuildsQuery) {
            throw new \LogicException('Query builders are not needed by this test adapter.');
        }

        $statement = $this->pdo->prepare($this->sql($query));
        $statement->execute($query->bindings);
    }

    public function getLastInsertId(): ?PrimaryKey
    {
        return null;
    }

    /** @return list<array<string, mixed>>
     *  @param BuildsQuery<object>|Query $query
     */
    public function fetch(BuildsQuery|Query $query): array
    {
        if ($query instanceof BuildsQuery) {
            throw new \LogicException('Query builders are not needed by this test adapter.');
        }

        $statement = $this->pdo->prepare($this->sql($query));
        $statement->execute($query->bindings);

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(
            static function (array $row): array {
                $normalized = [];

                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $normalized[$key] = $value;
                    }
                }

                return $normalized;
            },
            array_filter($rows, static fn(mixed $row): bool => is_array($row)),
        ));
    }

    /** @return array<string, mixed>|null
     *  @param BuildsQuery<object>|Query $query
     */
    public function fetchFirst(BuildsQuery|Query $query): ?array
    {
        return $this->fetch($query)[0] ?? null;
    }

    public function withinTransaction(callable $callback): bool
    {
        throw new \LogicException('Transactions are not needed by this test adapter.');
    }

    public function getRawSql(Query $query): ImmutableString
    {
        throw new \LogicException('Raw SQL is not needed by this test adapter.');
    }

    private function sql(Query $query): string
    {
        if (! is_string($query->sql)) {
            throw new \LogicException('Query statements are not needed by this test adapter.');
        }

        return $query->sql;
    }
}
