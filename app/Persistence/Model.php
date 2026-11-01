<?php

declare(strict_types=1);

namespace App\Persistence;

use App\Identity\Identifier;
use App\Identity\IdentifierType;
use App\Operations\Authority\PublicationAuthority;
use DateTimeImmutable;
use LogicException;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tempest\Clock\Clock;
use Tempest\Database\Builder\QueryBuilders\QueryBuilder;
use Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Uuid as UuidColumn;
use Tempest\Database\Virtual;
use Tempest\Reflection\PropertyReflector;

use function Tempest\Container\get;
use function Tempest\Database\inspect;
use function Tempest\Database\query;

/**
 * Share identity and persistence timestamps while leaving mapping and SQL to Tempest.
 *
 * Mutation policies apply to these instance methods. Direct Tempest query builders
 * remain a lower-level API and do not enforce these policies or set timestamps.
 * New identity domains declare an IDENTIFIER_TYPE constant; existing model mappings
 * live in IdentifierType. Neither approach repeats UUID or identifier properties.
 */
abstract class Model
{
    #[UuidColumn]
    public PrimaryKey $uuid;

    #[Virtual]
    public Identifier $id {
        get => Identifier::fromUuid(static::class, (string) ($this->uuid ??= new PrimaryKey((string) Uuid::v7())));
    }

    public DateTimeImmutable $created_at;
    public DateTimeImmutable $updated_at;

    /**
     * Start a normal Tempest selection, including its filtering and relationship APIs.
     *
     * @return SelectQueryBuilder<static>
     */
    public static function select(): SelectQueryBuilder
    {
        /** @var QueryBuilder<static> $builder */
        $builder = query(static::class);

        return $builder->select();
    }

    /**
     * Hydrate a row by UUID, rejecting a typed identifier from another domain.
     */
    public static function get(Identifier|PrimaryKey|string $id): ?static
    {
        if ($id instanceof Identifier) {
            $id = $id->uuidFor(IdentifierType::forModel(static::class));
        }

        return static::select()->get($id instanceof PrimaryKey ? $id : new PrimaryKey($id));
    }

    /**
     * Insert explicitly, retaining an identity already observed before persistence.
     * Failed or forbidden writes do not assign persistence timestamps to this instance.
     */
    final public function insert(): static
    {
        $inserted = $this->persist(Persistence::forModel(static::class)->insert, function (): static {
            $row = clone $this;
            $row->uuid ??= new PrimaryKey((string) Uuid::v7());
            $row->created_at = get(Clock::class)->toPsrClock()->now();
            $row->updated_at = $row->created_at;
            $model = inspect($row);
            $model->validate(...$model->getPropertyValues());
            query($row)->insert($row)->execute();

            return $row;
        });
        $this->uuid = $inserted->uuid;
        $this->created_at = $inserted->created_at;
        $this->updated_at = $inserted->updated_at;

        return $this;
    }

    /**
     * Validate and update named domain fields, preserving identity and creation time.
     * In-memory values change only after the protected database call succeeds.
     */
    final public function update(mixed ...$values): static
    {
        $updated = $this->persist(Persistence::forModel(static::class)->update, function () use ($values): array {
            foreach (['uuid', 'id', 'created_at', 'updated_at'] as $owned) {
                if (array_key_exists($owned, $values)) {
                    throw new LogicException('The model owns ' . $owned . '.');
                }
            }

            $values['updated_at'] = get(Clock::class)->toPsrClock()->now();
            inspect($this)->validate(...$values);
            query($this)->update(...$values)->where('uuid', $this->uuid)->execute();

            return $values;
        });
        foreach ($updated as $field => $value) {
            PropertyReflector::fromParts($this, (string) $field)->setValue($this, $value);
        }

        return $this;
    }

    /**
     * Delete only this UUID, after the concrete model's policy has been checked.
     */
    final public function delete(): void
    {
        $this->persist(Persistence::forModel(static::class)->delete, function (): void {
            query($this)->delete()->where('uuid', $this->uuid)->execute();
        });
    }

    /**
     * Reload persisted value fields on this instance; a missing row is an explicit error.
     */
    public function refresh(): static
    {
        $fresh = static::get($this->uuid) ?? throw new RuntimeException('The model no longer exists.');
        foreach (inspect($this)->getValueFields() as $property) {
            $property->setValue($this, $property->getValue($fresh));
        }

        return $this;
    }

    /**
     * Check permission before invoking any code that mutates the model or database.
     *
     * @template T
     * @param callable(): T $mutation
     * @return T
     */
    private function persist(Policy $policy, callable $mutation): mixed
    {
        return match ($policy) {
            Policy::Allowed => $mutation(),
            Policy::Forbidden => throw new LogicException('This model mutation is forbidden: ' . static::class),
            Policy::Fenced => get(PublicationAuthority::class)->protectCurrent($mutation),
        };
    }
}
