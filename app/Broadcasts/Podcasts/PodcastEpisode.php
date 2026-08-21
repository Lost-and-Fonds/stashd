<?php

declare(strict_types=1);

namespace App\Broadcasts\Podcasts;

use Tempest\DateTime\DateTime;

final readonly class PodcastEpisode
{
    public function __construct(
        public string $guid,
        public string $title,
        public string $description,
        public DateTime $publishedAt,
        public string $enclosureUrl,
        public int $enclosureLength,
        public string $enclosureMimeType,
        public ?int $durationSeconds = null,
        public ?string $imageUrl = null,
        public ?string $transcriptUrl = null,
        public ?string $transcriptMimeType = null,
        public ?string $transcriptLanguage = null,
        public ?string $chapterUrl = null,
        public ?string $publicationToken = null,
    ) {
    }
}
