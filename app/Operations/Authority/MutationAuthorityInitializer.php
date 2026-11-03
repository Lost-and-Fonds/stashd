<?php

declare(strict_types=1);

namespace App\Operations\Authority;

use App\Persistence\MutationAuthority;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Container\Singleton;

/**
 * Bind shared persistence fencing to Operations' existing publication authority.
 */
final class MutationAuthorityInitializer implements Initializer
{
    /**
     * Reuse the same authority instance used by application services.
     */
    #[Singleton]
    public function initialize(Container $container): MutationAuthority
    {
        return $container->get(PublicationAuthority::class);
    }
}
