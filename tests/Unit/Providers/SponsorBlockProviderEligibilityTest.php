<?php

declare(strict_types=1);

use App\Providers\SponsorBlockProviderEligibility;
use App\Vault\MediaItemRecord;
use App\Vault\MediaItemState;
use App\Vault\UpstreamState;

test('SponsorBlock eligibility follows logical YouTube identity', function (): void {
    $eligibility = new SponsorBlockProviderEligibility();
    $youtube = new MediaItemRecord('youtube', 'video-1', 'https://example.test/1', 'Video', MediaItemState::Discovered, UpstreamState::Available);
    $implementationIdentity = new MediaItemRecord('youtube-component', 'video-1', 'https://example.test/1', 'Video', MediaItemState::Discovered, UpstreamState::Available);

    expect($eligibility->supports($youtube))->toBeTrue()
        ->and($eligibility->supports($implementationIdentity))->toBeFalse();
});
