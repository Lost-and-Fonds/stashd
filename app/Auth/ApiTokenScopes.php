<?php

declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;
use Tempest\Mapper\SerializeAs;

#[SerializeAs('api_token_scopes')]
final readonly class ApiTokenScopes
{
    /** @param list<string> $values */
    public function __construct(
        public array $values = [],
    ) {}

    /** @param array<mixed>|null $values */
    public static function fromArray(?array $values): self
    {
        if ($values === null) {
            return new self();
        }

        $scopes = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('API token scopes must be strings.');
            }

            $scope = trim($value);

            if ($scope === '') {
                throw new InvalidArgumentException('API token scopes must not be empty.');
            }

            if (ApiScope::tryFrom($scope) === null) {
                throw new InvalidArgumentException('Unknown API token scope.');
            }

            $scopes[$scope] = $scope;
        }

        return new self(array_values($scopes));
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->values;
    }
}
