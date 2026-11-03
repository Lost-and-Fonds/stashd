<?php

declare(strict_types=1);

namespace App\Operations\Durable;

use App\Operations\DTO\AssignmentContext;
use App\Operations\Job;
use InvalidArgumentException;
use Tempest\Container\Singleton;
use Tempest\Reflection\ClassReflector;
use Tempest\Reflection\ParameterReflector;
use Tempest\Validation\Rules\MatchesRegEx;

/**
 * Bind stable persisted aliases to injectable handlers without constructing them at boot.
 */
#[Singleton]
final class JobRegistry
{
    /** @var array<class-string<Job>, class-string> */
    private array $handlers = [];

    /** @var array<string, class-string<Job>> */
    private array $types = [];

    /**
     * @param class-string<Job> $job
     * @param class-string $handler
     */
    public function register(string $job, string $handler, string $type): void
    {
        if (! is_subclass_of($job, Job::class) || isset($this->handlers[$job]) && $this->handlers[$job] !== $handler) {
            throw new InvalidArgumentException('A Job requires exactly one handler: ' . $job);
        }

        if (! class_exists($handler) || ! method_exists($handler, '__invoke')) {
            throw new InvalidArgumentException('A Job handler must be invokable.');
        }

        $method = new ClassReflector($handler)->getMethod('__invoke');
        /** @var list<ParameterReflector> $parameters */
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

        if (
            ! new MatchesRegEx('/\A[a-z][a-z0-9._-]{0,199}\z/D')->isValid($type)
            || isset($this->types[$type]) && $this->types[$type] !== $job
            || in_array($job, $this->types, true) && ($this->types[$type] ?? null) !== $job
        ) {
            throw new InvalidArgumentException('A Job requires a unique stable type alias.');
        }

        $this->types[$type] = $job;
        $this->handlers[$job] = $handler;
    }

    public function type(Job $job): string
    {
        $this->handler($job::class);

        return array_search($job::class, $this->types, true) ?: throw new InvalidArgumentException('No stable type registered for Job ' . $job::class);
    }

    /** @return class-string<Job> */
    public function job(string $type): string
    {
        return $this->types[$type] ?? throw new InvalidArgumentException('Unknown Job type ' . $type);
    }

    /** @return class-string */
    public function handler(string $job): string
    {
        return $this->handlers[$job] ?? throw new InvalidArgumentException('No handler discovered for Job ' . $job);
    }
}
