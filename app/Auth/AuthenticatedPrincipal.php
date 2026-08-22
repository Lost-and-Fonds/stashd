<?php

declare(strict_types=1);

namespace App\Auth;

final readonly class AuthenticatedPrincipal
{
    public function __construct(
        public UserRecord $user,
        public bool $session,
        public ?ApiTokenScopes $scopes = null,
    ) {}

    public function allows(?ApiScope $required): bool
    {
        if ($this->session || $this->scopes === null || $this->scopes->toArray() === []) {
            return true;
        }

        return $required !== null && in_array($required->value, $this->scopes->toArray(), true);
    }
}
