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
    case Derived = 'derived';

    /** @return list<self> */
    public static function preserved(): array
    {
        return [
            self::VaultOriginal,
            self::SourceThumbnail,
            self::Subtitle,
            self::Transcript,
            self::MetadataJson,
            self::SourceJson,
        ];
    }
}
