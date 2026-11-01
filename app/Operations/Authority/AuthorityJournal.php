<?php

declare(strict_types=1);

namespace App\Operations\Authority;

use DateTimeImmutable;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\PassFence;

/**
 * Add the missing Activity completion-authority hook around the upstream store.
 *
 * Workflow pass fencing is forwarded unchanged. Activity writes use a short
 * journal transaction which validates the exact live domain execution episode.
 */
final readonly class AuthorityJournal implements FencedEventStoreInterface
{
    /**
     * Delegate storage, history and workflow fencing entirely to Durable's backend.
     */
    public function __construct(
        private FencedEventStoreInterface $inner,
        private PublicationAuthority $authority,
    ) {}

    /**
     * Fence activity outcomes without spanning a workflow pass or handler execution.
     */
    public function append(Event $event): void
    {
        $this->authority->journal(fn () => $this->inner->append($event));
    }

    /**
     * Preserve upstream workflow pass epoch allocation.
     */
    public function claimPass(ExecutionId $executionId): PassFence
    {
        return $this->inner->claimPass($executionId);
    }

    /**
     * Preserve upstream conditional workflow journal writes.
     */
    public function appendFenced(Event $event, PassFence $fence): void
    {
        $this->inner->appendFenced($event, $fence);
    }

    /**
     * Stream upstream history without creating another event store.
     */
    public function readStream(ExecutionId $executionId): iterable
    {
        return $this->inner->readStream($executionId);
    }

    /**
     * Keep original journal timestamps for operational observations.
     *
     * @return iterable<array{event: Event, recordedAt: DateTimeImmutable|null}>
     */
    public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
    {
        return $this->inner->readStreamWithRecordedAt($executionId);
    }

    /**
     * Count upstream history without a competing projection.
     */
    public function countEventsInStream(ExecutionId $executionId): int
    {
        return $this->inner->countEventsInStream($executionId);
    }
}
