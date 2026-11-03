<?php

declare(strict_types=1);

use App\Operations\Durable\JobRegistry;
use Tests\Support\Durable\DepartmentHandler;
use Tests\Support\Durable\DepartmentJob;

test('Job registration uses a stable alias and rejects conflicting aliases', function (): void {
    $registry = new JobRegistry();
    $registry->register(DepartmentJob::class, DepartmentHandler::class, 'preservation.refresh.v1');
    expect($registry->type(new DepartmentJob('asset')))->toBe('preservation.refresh.v1');
    expect($registry->job('preservation.refresh.v1'))->toBe(DepartmentJob::class);
    expect(fn () => $registry->register(DepartmentJob::class, DepartmentHandler::class, 'another.type'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->job(DepartmentJob::class))->toThrow(InvalidArgumentException::class);
});

test('Job registration rejects an unstable or empty alias', function (string $alias): void {
    expect(fn () => new JobRegistry()->register(DepartmentJob::class, DepartmentHandler::class, $alias))->toThrow(InvalidArgumentException::class);
})->with(['', DepartmentJob::class]);
