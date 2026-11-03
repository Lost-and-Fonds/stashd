<?php

declare(strict_types=1);

use App\Operations\AssignmentState;
use App\Operations\Worker;
use App\RecordsAndArchives\VerifyAsset;
use Tempest\Storage\Config\LocalStorageConfig;
use Tests\Support\Durable\DepartmentFixture;
use Tests\Support\RecordsAndArchives\OcflInspector;

beforeEach(function (): void {
    $this->department = new DepartmentFixture();
    $this->department->container->config(new LocalStorageConfig(path: $this->archive->path('vault'), tag: 'vault'));
    $this->worker = $this->department->container->get(Worker::class);
    $this->archive->run('vault.initialize');
    $this->first = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/verify/1',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('first.bin', 'first version bytes')],
    ]);
    $this->second = $this->archive->run('asset.preserve', [
        'item_uri' => 'https://example.test/verify/1',
        'asset_key' => 'master',
        'files' => ['data.bin' => $this->archive->input('second.bin', 'second version bytes')],
    ]);
});
afterEach(function (): void {
    if (isset($this->department)) {
        $this->department->dispose();
    }
});

/**
 * Hash every preserved file to detect added versions or changed archival bytes.
 */
function verification_snapshot(string $root): array
{
    $snapshot = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $snapshot[substr($file->getPathname(), strlen($root))] = hash_file('sha512', $file->getPathname());
    }

    ksort($snapshot);

    return $snapshot;
}

test('VerifyAsset checks an exact historical version through Messenger and Durable without changing the Vault', function (): void {
    // A corrupt head must not prevent verification of the healthy requested version.
    $head = OcflInspector::payloadPath($this->archive->path('vault'), $this->second['object_id'], 'v2', 'data.bin');
    file_put_contents($head, 'corrupt head');
    $before = verification_snapshot($this->archive->path('vault'));
    $job = new VerifyAsset($this->first['object_id'], 'v1');
    $assignment = $this->worker->do($job);
    expect($assignment->state)->toBe(AssignmentState::Queued);
    $this->department->runtime->drain();
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Completed);
    $db = $this->department->connections->application;
    $result = $db->fetchAssociative('SELECT * FROM operation_results');
    expect(json_decode($result['coverage'], true, flags: JSON_THROW_ON_ERROR))->toBe([
        'object_id' => $this->first['object_id'],
        'version' => 'v1',
        'files' => $this->first['files'],
    ]);
    expect($db->fetchFirstColumn('SELECT outcome FROM operation_attempts'))->toBe(['completed']);
    expect($db->fetchOne('SELECT satisfied FROM operation_demands'))->toBeTrue();
    expect(verification_snapshot($this->archive->path('vault')))->toBe($before);
});

test('corrupt requested bytes exhaust existing Durable retries without a successful result or repair', function (): void {
    $path = OcflInspector::payloadPath($this->archive->path('vault'), $this->first['object_id'], 'v1', 'data.bin');
    file_put_contents($path, 'corrupt requested bytes');
    $before = verification_snapshot($this->archive->path('vault'));
    $assignment = $this->worker->do(new VerifyAsset($this->first['object_id'], 'v1'));
    $this->department->runtime->drain();
    expect($this->worker->status($assignment->id)->state)->toBe(AssignmentState::Failed);
    expect($this->worker->status($assignment->id)->error)->toContain('Archived bytes differ');
    $db = $this->department->connections->application;
    expect($db->fetchFirstColumn('SELECT outcome FROM operation_attempts ORDER BY started_at'))->toBe(['failed', 'failed', 'failed', 'failed']);
    expect($db->fetchOne('SELECT count(*) FROM operation_results'))->toBe(0);
    expect($db->fetchOne('SELECT satisfied FROM operation_demands'))->toBeFalse();
    expect(verification_snapshot($this->archive->path('vault')))->toBe($before);
});

test('equivalent active verifications share one execution while exact versions remain distinct', function (): void {
    $first = $this->worker->do(new VerifyAsset($this->first['object_id'], 'v1'));
    $again = $this->worker->do(new VerifyAsset($this->first['object_id'], 'v1'));
    $second = $this->worker->do(new VerifyAsset($this->first['object_id'], 'v2'));
    expect($again->id)->toEqual($first->id);
    expect($second->id)->not->toEqual($first->id);
    $this->department->runtime->drain();
    expect($this->worker->status($first->id)->state)->toBe(AssignmentState::Completed);
    expect($this->worker->status($second->id)->state)->toBe(AssignmentState::Completed);
    $db = $this->department->connections->application;
    expect($db->fetchOne('SELECT count(*) FROM operation_demands'))->toBe(2);
    expect($db->fetchFirstColumn('SELECT outcome FROM operation_attempts'))->toBe(['completed', 'completed']);
    expect($db->fetchOne('SELECT count(*) FROM operation_results'))->toBe(2);
});
