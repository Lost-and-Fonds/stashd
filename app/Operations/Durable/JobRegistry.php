<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\DTO\AssignmentContext;
use App\Operations\Job;
use InvalidArgumentException;
use Tempest\Container\Singleton;
use Tempest\Reflection\ClassReflector;

/**
 * Retain discovered handler classes without constructing their dependencies at boot.
 */
#[Singleton]
final class JobRegistry
{
    /** @var array<class-string<Job>, class-string> */
    public array $handlers = [];

    /**
     * Reject ambiguous registrations before any work can be admitted.
     *
     * @param class-string<Job> $job
     * @param class-string $handler
     */
    public function register(string $job, string $handler): void
    {
        if (! is_subclass_of($job, Job::class) || isset($this->handlers[$job]) && $this->handlers[$job] !== $handler) {
            throw new InvalidArgumentException('A Job requires exactly one handler: ' . $job);
        }

        if (! class_exists($handler) || ! method_exists($handler, '__invoke')) {
            throw new InvalidArgumentException('A Job handler must be invokable.');
        }

        $method = new ClassReflector($handler)->getMethod('__invoke');
        /** @var list<\Tempest\Reflection\ParameterReflector> $parameters */
        $parameters = iterator_to_array($method->getParameters(), false);
        if (
            ! $method->getReflection()->isPublic()
            || $method->getReflection()->isStatic()
            || count($parameters) !== 2
            || $parameters[0]->getType()->getName() !== $job
            || $parameters[1]->getType()->getName() !== AssignmentContext::class
        ) {
            throw new InvalidArgumentException('A Job handler requires a public __invoke(ConcreteJob, AssignmentContext) method.');
        }

        $this->handlers[$job] = $handler;
    }

    /**
     * Fail admission rather than silently retaining work no worker can execute.
     *
     * @return class-string
     */
    public function handler(string $job): string
    {
        return $this->handlers[$job] ?? throw new InvalidArgumentException('No handler discovered for Job ' . $job);
    }
}
