<?php

namespace App\RecordsAndArchives;

enum IdentifierType: string
{
    case Item = 'item';
    case Asset = 'asset';
    case Vault = 'vault';
}
