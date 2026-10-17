<?php

namespace App\RecordsAndArchives;

enum ObjectType: string
{
    case Item = 'item';
    case Asset = 'asset';
    case Vault = 'vault';
}
