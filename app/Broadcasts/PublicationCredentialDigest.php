<?php

declare(strict_types=1);

namespace App\Broadcasts;

use RuntimeException;
use SensitiveParameter;

use function Tempest\env;

final class PublicationCredentialDigest
{
    public function for(#[SensitiveParameter] string $credential): string
    {
        $key = env('SIGNING_KEY');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Publication credential lookup requires a configured signing key.');
        }

        return hash_hmac('sha256', "stashd:publication-credential:v1\0" . $credential, $key);
    }
}
