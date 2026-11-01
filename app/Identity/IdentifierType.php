<?php

namespace App\Identity;

/**
 * Keep archive and Operations identities in distinct Stashd URN namespaces.
 */
enum IdentifierType: string
{
    case Item = 'item';
    case Asset = 'asset';
    case Vault = 'vault';
    case Assignment = 'assignment';
    case Attempt = 'attempt';
    case Demand = 'demand';
}
