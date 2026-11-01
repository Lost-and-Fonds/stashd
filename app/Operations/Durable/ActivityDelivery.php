<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\Persistence\Assignments;
use App\Operations\Priority;
use Gplanchat\Durable\Transport\ActivityMessage;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Route departmental activities by admitted priority and retain recoverable scheduling.
 *
 * Durable has already journalled ActivityScheduled before calling this sender.
 * For an infrastructure enqueue error, keep that unresolved event resumable;
 * the recovery command reissues only activities that have never started.
 */
final readonly class ActivityDelivery implements SenderInterface
{
    /**
     * Keep queue adapters upstream and use domain models only for service priority.
     */
    public function __construct(
        private SenderInterface $routine,
        private SenderInterface $interactive,
        private Assignments $assignments,
        private LoggerInterface $logger,
    ) {}

    /**
     * Report a failed physical send without turning it into domain workflow failure.
     */
    public function send(Envelope $envelope): Envelope
    {
        $message = $envelope->getMessage();
        if (! $message instanceof ActivityMessage) {
            throw new LogicException('Activity routing requires an ActivityMessage.');
        }

        $record = isset($message->payload['assignment']) ? $this->assignments->find($message->payload['assignment']) : null;
        $sender = $record?->priority === Priority::Interactive ? $this->interactive : $this->routine;
        try {
            return $sender->send($envelope);
        } catch (TransportException $failure) {
            if ($message->attempt !== 1) {
                throw $failure;
            }

            $this->logger->error('Activity enqueue failed; its journalled schedule requires operations:recover.', ['execution' => $message->executionId, 'exception' => $failure]);

            return $envelope;
        }
    }
}
