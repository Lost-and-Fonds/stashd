<?php

declare(strict_types=1);

namespace App\Auth;

use Tempest\Container\Resettable;
use Tempest\Container\Singleton;

/** Request-scoped authenticated user resolved by middleware. */
#[Singleton]
final class AuthContext implements Resettable
{
    private ?AuthenticatedPrincipal $principal = null;

    public function set(?UserRecord $user): void
    {
        $this->principal = $user === null ? null : new AuthenticatedPrincipal($user, session: true);
    }

    public function setPrincipal(?AuthenticatedPrincipal $principal): void
    {
        $this->principal = $principal;
    }

    public function user(): ?UserRecord
    {
        return $this->principal?->user;
    }

    public function requireUser(): UserRecord
    {
        return ($this->principal ?? throw new AuthenticationRequired('Authentication required.'))->user;
    }

    public function principal(): ?AuthenticatedPrincipal
    {
        return $this->principal;
    }

    public function reset(): void
    {
        $this->principal = null;
    }
}
