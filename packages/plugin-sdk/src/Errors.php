<?php

declare(strict_types=1);

namespace Stashd\PluginSdk;

use RuntimeException;

enum PluginErrorCode: string
{
    case Unsupported = 'unsupported';
    case NotFound = 'not-found';
    case Authentication = 'authentication';
    case RateLimited = 'rate-limited';
    case Unavailable = 'unavailable';
    case InvalidData = 'invalid-data';
    case Failed = 'failed';
}

final readonly class PluginError
{
    public function __construct(public string $message, public bool $retryable)
    {
    }
}

final readonly class PluginFailure
{
    public function __construct(public PluginErrorCode $code, public PluginError $error)
    {
    }
}

final class PluginFailureException extends RuntimeException
{
    public function __construct(public readonly PluginFailure $failure)
    {
        parent::__construct($failure->error->message);
    }
}

final class InvalidPluginResultException extends RuntimeException
{
}
final class CapabilityUnavailableException extends RuntimeException
{
}
