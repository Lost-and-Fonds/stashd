<?php

declare(strict_types=1);

use App\Operations\OperationsWorkerPool;
use Tempest\Process\GenericProcessExecutor;
use Tempest\Process\PendingProcess;
use Tempest\Process\ProcessExecutor;

test('a process startup exception stops and reaps every consumer already acquired', function (): void {
    $executor = $this->createStub(ProcessExecutor::class);
    $started = [];
    $executor
        ->method('start')
        ->willReturnCallback(static function (PendingProcess $command) use (&$started) {
            if (count($started) === 1) {
                throw new RuntimeException('Deliberate process launch failure.');
            }

            $process = new GenericProcessExecutor()->start($command);
            $started[] = $process->pid;

            return $process;
        });
    $pool = new OperationsWorkerPool($executor);
    expect(fn () => $pool->start(new PendingProcess(command: [PHP_BINARY, 'tests/Support/Operations/process-probe.php']), 4))
        ->toThrow(RuntimeException::class, 'Deliberate process launch failure.');
    expect($pool->pids())->toBe($started)->toHaveCount(1);
    expect(is_dir('/proc/' . $started[0]))->toBeFalse();
});

test('uncooperative consumers are killed and reaped after the shared grace deadline', function (): void {
    $pool = new OperationsWorkerPool(new GenericProcessExecutor());
    try {
        $pool->start(new PendingProcess(command: [PHP_BINARY, 'tests/Support/Operations/process-probe.php', 'ignore']), 2);
        $pool->stop(0.05);
        foreach ($pool->pids() as $pid) {
            expect(is_dir('/proc/' . $pid))->toBeFalse();
        }

        $pool->stop();
    } finally {
        $pool->stop(0);
    }
});

test('invalid worker counts are refused without invoking any process', function (): void {
    $executor = $this->createMock(ProcessExecutor::class);
    $executor->expects($this->never())->method('start');
    expect(fn () => new OperationsWorkerPool($executor)->start(new PendingProcess(), 0))->toThrow(InvalidArgumentException::class);
});

test('an inherited pool copy cannot supervise its owners children', function (): void {
    $pool = new OperationsWorkerPool(new GenericProcessExecutor());
    new ReflectionProperty($pool, 'owner')->setValue($pool, posix_getpid() + 1);
    expect($pool->stop(...))->toThrow(RuntimeException::class, 'Only the process that created');
});

test('one failed stop does not skip cleanup attempts for the remaining consumers', function (): void {
    $first = $this->createStub(Tempest\Process\InvokedProcess::class);
    $second = $this->createMock(Tempest\Process\InvokedProcess::class);
    $first->method('stop')->willThrowException(new RuntimeException('Control failure.'));
    $second->expects($this->once())->method('stop')->with(0)->willReturnSelf();
    $pool = new OperationsWorkerPool(new GenericProcessExecutor());
    new ReflectionProperty($pool, 'processes')->setValue($pool, [$first, $second]);
    expect(fn () => $pool->stop(0))->toThrow(RuntimeException::class, 'cleanup could not be confirmed');
});

test('startup and cleanup failures remain independently inspectable', function (): void {
    $startup = new RuntimeException('Launch failed.');
    $cleanup = new RuntimeException('Stop failed.');
    $process = $this->createStub(Tempest\Process\InvokedProcess::class);
    $process->method('stop')->willThrowException($cleanup);
    $executor = $this->createStub(ProcessExecutor::class);
    $executor->method('start')->willThrowException($startup);
    $pool = new OperationsWorkerPool($executor);
    new ReflectionProperty($pool, 'processes')->setValue($pool, [$process]);
    try {
        $pool->start(new PendingProcess(), 1);
        $this->fail('Startup must fail.');
    } catch (App\Operations\OperationsWorkerStartupException $error) {
        expect($error->getPrevious())->toBe($startup);
        expect($error->cleanupFailure->getPrevious())->toBe($cleanup);
    }
});
