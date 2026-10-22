<?php

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RecordsAndArchives\ArchiveFixture;
use Tests\Support\RecordsAndArchives\TransactionProbe;

/**
 * Read a real temporary JSON file through the helper and always dispose its fixture.
 *
 * @return array<string, mixed>
 */
function probe_contract_json(string $json): array
{
    $fixture = new ArchiveFixture();
    try {
        return TransactionProbe::json($fixture->input('record.json', $json));
    } finally {
        $fixture->dispose();
    }
}

/**
 * Exercise real child supervision outside PHPUnit's warning handler and retain native diagnostics.
 *
 * The worker uses disposable real processes and files, not mocked reads or process state.
 *
 * @return array{result: array<array-key, mixed>, stdout: string, stderr: string}
 */
function probe_contract_process(string $mode): array
{
    $fixture = new ArchiveFixture();
    try {
        $workspace = dirname($fixture->path('vault'));
        Assert::assertTrue(\PHPUnit\TextUI\Configuration\Registry::saveTo($workspace . '/phpunit.configuration'));
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/RecordsAndArchives/transaction-probe-contract-process.php', $workspace, $mode],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $workspace . '/worker.out', 'w'], 2 => ['file', $workspace . '/worker.err', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process);
        $exit = proc_close($process);
        $stdout = file_get_contents($workspace . '/worker.out');
        $stderr = file_get_contents($workspace . '/worker.err');
        Assert::assertIsString($stdout);
        Assert::assertIsString($stderr);
        Assert::assertSame(0, $exit, $stdout . $stderr);
        $raw = file_get_contents($workspace . '/result.json');
        Assert::assertIsString($raw);
        $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($result);
        return compact('result', 'stdout', 'stderr');
    } finally {
        $fixture->dispose();
    }
}

test('probe JSON accepts objects without changing valid nested data', function (string $json, array $expected): void {
    expect(probe_contract_json($json))->toBe($expected);
})->with([
    'empty object' => ['{}', []],
    'whitespace' => [" \r\n\t {} \n", []],
    'ordinary object' => ['{"label":"value"}', ['label' => 'value']],
    'nested data' => ['{"list":[1,2],"object":{},"numeric":1.0,"nested":{"0":"value"}}', ['list' => [1, 2], 'object' => [], 'numeric' => 1.0, 'nested' => [0 => 'value']]],
    'noncoercing keys' => ['{"01":"leading zero","+1":"plus"}', ['01' => 'leading zero', '+1' => 'plus']],
]);

test('probe JSON rejects lists and integer-coercing object keys', function (string $json): void {
    expect(fn () => probe_contract_json($json))->toThrow(AssertionFailedError::class);
})->with(['[]', '[1,2]', '{"0":"zero"}', '{"1":"one"}', '{"-1":"negative"}']);

test('probe JSON retains scalar rejection', function (string $json): void {
    expect(fn () => probe_contract_json($json))->toThrow(AssertionFailedError::class);
})->with(['null', 'true', '1', '"string"']);

test('probe JSON preserves decoding errors', function (string $json): void {
    expect(fn () => probe_contract_json($json))->toThrow(JsonException::class);
})->with(['{', '{"value":}', '[1,']);

test('probe finish rejects unreadable logs after reaping and retains native warnings', function (string $mode, string $channel, string $suffix): void {
    $observed = probe_contract_process($mode);
    expect($observed['result']['status'])->toBe('rejected');
    expect($observed['result']['process_closed'])->toBeTrue();
    $message = $observed['result']['message'];
    Assert::assertIsString($message);
    expect($message)->toContain('Could not read transaction child ' . $channel . ' log:');
    expect($message)->toContain('.' . $suffix);
    expect($observed['stdout'] . $observed['stderr'])->toContain('file_get_contents(');
    expect($observed['stdout'] . $observed['stderr'])->toContain('Failed to open stream');
})->with([
    ['missing-stdout', 'stdout', 'out'],
    ['missing-stderr', 'stderr', 'err'],
    ['missing-both',   'stdout', 'out'],
]);

test('probe finish preserves successful and empty output and nonzero exit evidence', function (string $mode, int $exit, string $out, string $err): void {
    $observed = probe_contract_process($mode);
    expect($observed['result']['status'])->toBe('returned');
    expect($observed['result']['process_closed'])->toBeTrue();
    expect($observed['result']['value'])->toBe(['exit' => $exit, 'signal' => 0, 'out' => $out, 'err' => $err]);
    expect($observed['stdout'] . $observed['stderr'])->toBe('');
})->with([
    ['normal',  0, '{"status":"probe-complete"}', ''],
    ['empty',   0, '',                            ''],
    ['nonzero', 7, 'stdout bytes',                'stderr bytes'],
]);

test('probe finish preserves real crash signal and boundary evidence', function (): void {
    $observed = probe_contract_process('crash');
    expect($observed['result']['status'])->toBe('returned');
    expect($observed['result']['process_closed'])->toBeTrue();
    $value = $observed['result']['value'];
    Assert::assertIsArray($value);
    expect($value['signal'])->toBe(9);
    expect($value['out'])->toBe('');
    expect($value['err'])->toBe('');
});

test('probe finish still rejects a boundary that was never reached', function (): void {
    $observed = probe_contract_process('missing-boundary');
    expect($observed['result']['status'])->toBe('rejected');
    expect($observed['result']['process_closed'])->toBeTrue();
    expect($observed['result']['message'])->toContain('Requested boundary was never reached');
});
