<?php

declare(strict_types=1);

namespace App\Development\DTO;

/**
 * Carry observed progress separately from terminal rendering and final acceptance evidence.
 * Null counts mean the relevant tables or samples are not available yet.
 */
final readonly class FilesystemStressProgress
{
    /**
     * Describe the current serial run without equating settled Jobs with verified bytes.
     */
    public function __construct(
        public string $phase,
        public float $elapsedSeconds,
        public ?int $discovered,
        public ?int $admitted,
        public ?int $settled,
        public int $preserved,
        public int $verified,
        public ?int $outstanding,
        public ?int $failed,
        public int $activeWorkers,
        public ?int $peakRssKib,
    ) {}
}
