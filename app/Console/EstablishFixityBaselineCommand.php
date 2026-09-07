<?php

declare(strict_types=1);

namespace App\Console;

use App\Fixity\EstablishFixityBaseline;
use App\Fixity\EstablishFixityBaselineOutcome;
use App\Vault\AssetId;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Console\HasConsole;

final readonly class EstablishFixityBaselineCommand
{
    use HasConsole;

    public function __construct(private EstablishFixityBaseline $baselines) {}

    #[ConsoleCommand(
        name: 'stashd:fixity-establish-baseline',
        description: 'Explicitly establish a retrospective SHA-256 baseline for one legacy Vault asset.',
    )]
    public function __invoke(#[ConsoleArgument(description: 'Asset ID')] string $assetId): ExitCode
    {
        $result = $this->baselines->establish(AssetId::parse($assetId));

        if ($result->outcome === EstablishFixityBaselineOutcome::Established) {
            $this->console->success("Established retrospective SHA-256 baseline for asset {$assetId}. The asset remains unverified until a later explicit fixity check succeeds.");

            return ExitCode::SUCCESS;
        }

        $this->console->error(match ($result->outcome) {
            EstablishFixityBaselineOutcome::AlreadyHasBaseline => "Asset {$assetId} already has an expected checksum; it was not changed.",
            EstablishFixityBaselineOutcome::NotEligible => "Asset {$assetId} is not a ready, preserved Vault asset with a path.",
            EstablishFixityBaselineOutcome::StorageUnavailable => 'Vault storage is unavailable; no baseline was established.',
            EstablishFixityBaselineOutcome::FileUnavailable => "Asset {$assetId} is missing or unreadable; no baseline was established.",
            EstablishFixityBaselineOutcome::ChecksumFailed => "Unable to compute a SHA-256 checksum for asset {$assetId}.",
            EstablishFixityBaselineOutcome::NotFound => "Asset {$assetId} was not found.",
        });

        return ExitCode::ERROR;
    }
}
