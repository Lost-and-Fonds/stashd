<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\Assignment;
use App\Operations\Priority;
use Gplanchat\Durable\Transport\ActivityMessage;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

use function Tempest\Database\query;

/**
 * Route departmental activities by admitted priority.
 */
final readonly class ActivityDelivery implements SenderInterface
{
    /**
     * Keep queue adapters upstream and use domain models only for service priority.
     */
    public function __construct(
        private SenderInterface $routine,
        private SenderInterface $interactive,
    ) {}

    /**
     * Preserve native send failures so Durable decides the workflow outcome.
     */
    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        if (! $message instanceof ActivityMessage) {
            throw new LogicException('Activity routing requires an ActivityMessage.');
        }

        /** @var Assignment|null $record */
        $record = isset($message->payload['assignment']) ? query(Assignment::class)->select()->where('uuid', $message->payload['assignment'])->first() : null;
        $sender = $record?->priority === Priority::Interactive ? $this->interactive : $this->routine;

        return $sender->send($envelope);
    }
}
