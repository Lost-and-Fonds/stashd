<?php

declare(strict_types=1);

namespace App\Vault;

enum AssetRole: string
{
    case VaultOriginal = 'vault_original';
    case SourceThumbnail = 'source_thumbnail';
    case Subtitle = 'subtitle';
    case Transcript = 'transcript';
    case Nfo = 'nfo';
    case Hardlink = 'hardlink';
    case MetadataJson = 'metadata_json';
    case SourceJson = 'source_json';
}
