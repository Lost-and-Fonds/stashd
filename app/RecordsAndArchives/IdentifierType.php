<?php

namespace App\RecordsAndArchives;

/**
 * Distinguish Item, Asset and Vault namespaces when formatting permanent Stashd URNs.
 */
enum IdentifierType: string
{
    case Item = 'item';
    case Asset = 'asset';
    case Vault = 'vault';
}
