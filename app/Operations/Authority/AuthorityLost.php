<?php

declare(strict_types=1);

namespace App\Operations\Authority;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Reject stale publication without asking the transport to retry the stale episode.
 */
final class AuthorityLost extends UnrecoverableMessageHandlingException {}
