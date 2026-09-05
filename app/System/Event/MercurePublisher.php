<?php

declare(strict_types=1);

namespace App\System\Event;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Tempest\Log\Logger;
use Throwable;

use function Tempest\Support\Json\encode;

/**
 * Publishes to the single topic every Stashd client subscribes to (a
 * single-user homelab app has no need for per-entity topic fan-out). Used
 * from both web requests and the out-of-process worker/scheduler CLI roles,
 * so this always publishes over HTTP to the FrankenPHP-embedded hub rather
 * than via the `mercure_publish()` function, which only exists inside a
 * FrankenPHP-handled request.
 */
final readonly class MercurePublisher
{
    public const string TOPIC = 'stashd/events';

    public function __construct(
        private HubInterface $hub,
        private Logger $logger,
    ) {}

    /** @param array<string, mixed> $payload */
    public function publish(string $eventName, array $payload): void
    {
        try {
            // `event` (not `type`) as the envelope discriminator key: some
            // payloads (activity.created) already carry their own `type`
            // field (the activity's own type, e.g. "command.accepted"), and
            // an array literal's later key wins, so `type` would silently
            // shadow it. `event` never collides with any current payload key.
            $this->hub->publish(new Update(
                topics: self::TOPIC,
                data: encode(['event' => $eventName, ...$payload]),
                private: true,
            ));
        } catch (Throwable $exception) {
            // A down/unreachable hub must never fail the job or request that
            // triggered this notification -- it's a nudge, and the UI
            // re-fetches state on reconnect regardless.
            static $lastWarningAt = 0.0;
            static $suppressed = 0;
            /** @var float $lastWarningAt */
            /** @var int $suppressed */
            $now = microtime(true);

            if ($now - $lastWarningAt < 60.0) {
                $suppressed++;

                return;
            }

            $this->logger->warning('Mercure publish failed', [
                'event' => $eventName,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'suppressed' => $suppressed,
            ]);
            $lastWarningAt = $now;
            $suppressed = 0;
        }
    }
}
