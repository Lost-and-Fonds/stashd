<?php

declare(strict_types=1);

namespace App\Vault;

enum MediaItemState: string
{
    case Discovered = 'discovered';
    case MetadataReady = 'metadata_ready';
    case DownloadPending = 'download_pending';
    case Downloading = 'downloading';
    case Ready = 'ready';
    case Failed = 'failed';
    case Ignored = 'ignored';
    case Missing = 'missing';

    /** @return list<MediaItemState> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Discovered => [self::MetadataReady, self::Ignored, self::Failed],
            self::MetadataReady => [self::DownloadPending, self::Ignored, self::Failed],
            self::DownloadPending => [self::Downloading, self::Ignored, self::Failed],
            self::Downloading => [self::Ready, self::Failed],
            self::Ready => [self::MetadataReady, self::DownloadPending, self::Missing],
            self::Failed => [self::Discovered, self::DownloadPending, self::Missing],
            self::Ignored => [self::Discovered],
            self::Missing => [self::Ready, self::DownloadPending, self::Failed],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
