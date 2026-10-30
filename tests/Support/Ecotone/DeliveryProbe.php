<?php

declare(strict_types=1);

namespace Tests\Support\Ecotone;

use App\Operations\DTO\ExecutionContext;
use App\Operations\JobRuntime;
use App\Operations\Work;
use App\Operations\WorkExecutor;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Interceptor\MethodInvocation;
use PDO;
use RuntimeException;
use Tests\Support\Operations\ProbeExecutor;

/**
 * Pause actual execution before an effect or after durable completion, before ack.
 * No acknowledgement, claim or effect ledger is implemented by this test probe.
 */
final readonly class DeliveryProbe implements WorkExecutor
{
    /**
     * Retain independent fixture credentials and a bounded test-only pause path.
     */
    public function __construct(
        private PDO $observer,
        private string $boundary,
        private string $path,
    ) {}

    /**
     * Delegate the real independent effect, or inject a domain handler failure.
     */
    public function execute(Work $work, ExecutionContext $context): void
    {
        if ($this->boundary === 'claimed') {
            $this->pause();
        }

        if ($work->observation === 'domain-fail') {
            throw new RuntimeException('Injected domain failure.');
        }

        new ProbeExecutor($this->observer)->execute($work, $context);
    }

    /**
     * Stop after the real handler has saved completion, still inside native delivery.
     */
    #[Around(pointcut: JobRuntime::class . '::handle')]
    public function after(MethodInvocation $invocation): mixed
    {
        $result = $invocation->proceed();
        if ($this->boundary === 'completed') {
            $this->pause();
        }

        return $result;
    }

    /**
     * Report process identity then wait for parent termination or fail on deadline.
     */
    private function pause(): void
    {
        file_put_contents($this->path, json_encode(['pid' => getmypid(), 'boundary' => $this->boundary], JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 12;
        while (microtime(true) < $deadline) {
            usleep(10_000);
        }

        throw new RuntimeException('Parent did not terminate paused delivery before deadline.');
    }
}
