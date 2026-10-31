#!/usr/bin/env python3
"""Run precise native durability sabotage with restoration; never accept infrastructure errors."""
import json
import fcntl
import os
import re
import hashlib
from pathlib import Path
import signal
import subprocess
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
TEST = 'tests/Acceptance/EcotoneDelivery/NativeDurabilityTest.php'
RUNTIME = 'app/Operations/JobRuntime.php'
abort = False


def replace_once(source, old, new):
    """Refuse drift instead of silently applying an ineffective mutation."""
    if source.count(old) != 1:
        raise ValueError(f'Expected exactly one mutation anchor: {old[:80]}')
    return source.replace(old, new, 1)


def classify(xml, code, test, location, events):
    """Require one specific assertion, no setup/teardown errors and no timeouts."""
    try:
        cases = ET.fromstring(xml).findall('.//testcase')
        if len(cases) != 1 or test not in cases[0].get('name', '') or code != 1:
            return False
        problems = list(cases[0])
        if len(problems) != 1 or problems[0].tag != 'failure':
            return False
        failure = problems[0]
        body = failure.text or ''
        if failure.get('type') != 'PHPUnit\\Framework\\ExpectationFailedException' or 'Failed asserting' not in body:
            return False
        frames = list(re.finditer(r'(?m)^(?:at )?([^\n]+\.php):(\d+)\s*$', body))
        if not frames or f'{frames[0][1]}:{frames[0][2]}' != location:
            return False
        if any(line.strip() and not re.fullmatch(r'(?:at )?[^\n]+\.php:\d+', line.strip()) for line in body[frames[0].start():].splitlines()):
            return False
        if re.search(r'RuntimeException:|PDOException:|SQLSTATE|TearDown|tearDown|setUp', body):
            return False
        return not any('Method Errored (' in line or line.startswith('Test Preparation Failed (') for line in events.splitlines())
    except ET.ParseError:
        return False


def self_test():
    """Check that errors, wrong assertions, timeouts and hook failures are rejected."""
    xml = '<testsuite><testcase name="case"><failure type="PHPUnit\\Framework\\ExpectationFailedException">Failed asserting\nx.php:4</failure></testcase></testsuite>'
    assert classify(xml, 1, 'case', 'x.php:4', '')
    assert not classify(xml, 2, 'case', 'x.php:4', '')
    assert not classify(xml, 1, 'case', 'x.php:5', '')
    assert not classify(xml.replace('failure', 'error'), 1, 'case', 'x.php:4', '')
    assert not classify(xml, 1, 'case', 'x.php:4', 'After Test Method Errored (cleanup)')


def run_test(folder, selected=None, test_path=TEST):
    """Use only the canonical wrapper; every application child also has a deadline."""
    xml, events = folder / 'result.xml', folder / 'events.txt'
    command = ['./bin/test.sh', test_path, '--colors=never', '--log-junit', str(xml), '--log-events-text', str(events)]
    if selected:
        command += ['--filter', selected]
    result = subprocess.run(command, cwd=ROOT, env={**os.environ, 'STASHD_TEST_PROCESSES': '1'}, capture_output=True, timeout=120)
    (folder / 'console.txt').write_bytes(result.stdout + result.stderr)
    return result.returncode, xml.read_text(), events.read_text()


def stop(signum, frame):
    """Finish bounded test cleanup before source restoration and stopping."""
    global abort
    abort = True


def mutated(path, transform, action, backups):
    """Restore exact pre-existing bytes on exceptions and deferred signals."""
    target = ROOT / path
    if target.is_symlink() or target.resolve() != target or target.stat().st_nlink != 1:
        raise ValueError('Mutation target is shared or linked')
    original = target.read_bytes()
    replacement = transform(original.decode()).encode()
    backup = backups / (hashlib.sha256(path.encode()).hexdigest() + '.original')
    backup.write_bytes(original)
    backup.chmod(0o600)
    try:
        target.write_bytes(replacement)
        return action()
    finally:
        if target.read_bytes() != replacement:
            raise ValueError('Concurrent source edit: original retained in private backup; refusing to overwrite it')
        target.write_bytes(original)
        if target.read_bytes() != original:
            raise ValueError('Source restoration hash mismatch')
        backup.unlink()



def main():
    """Require baseline, apply isolated controls, restore exact bytes, rerun positives."""
    self_test()
    branch = subprocess.check_output(['git', 'branch', '--show-current'], cwd=ROOT, text=True).strip()
    if branch != 'spike/ecotone-evaluation':
        raise RuntimeError('This verifier is restricted to the evaluation branch.')
    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)
    controls = [
        ('split-admission', RUNTIME,
         '$this->dispatchWork($id, 0, $priority);', '$database->commit(); $database->beginTransaction(); $this->dispatchWork($id, 0, $priority);',
         'queue insertion rejection', "expect($f->count('jobs'))->toBe(0);"),
        ('drop-intent', RUNTIME,
         '$this->commands->send(new ExecuteJob($id, $generation), metadata: [', 'return;\n        $this->commands->send(new ExecuteJob($id, $generation), metadata: [',
         'committed intent survives', "expect($f->count('enqueue'))->toBe(1);"),
        ('reclaim-completed', RUNTIME,
         "AND (status = 'queued' OR (status = 'running'", "AND (status IN ('queued', 'completed') OR (status = 'running'",
         'completion before acknowledgement death', "expect($f->count('job_attempts'))->toBe(1);"),
        ('repeat-effect', 'tests/Support/Operations/ProbeExecutor.php',
         '$effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);',
         '$effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);\n        $effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);',
         'committed intent survives', "expect($f->rows('SELECT job_id FROM probe_effects'))->toBe([['job_id' => $id]]);"),
        ('ignore-generation', RUNTIME,
         "AND generation = ? AND (status", "AND generation >= ? AND (status",
         'duplicates and obsolete', "expect($f->count('job_attempts'))->toBe(0);"),
        ('drop-busy-intent', RUNTIME,
         '$this->dispatchWork($message->id, $message->generation, $priority, 60_000);', '// deliberate loss of busy recovery intent',
         'busy duplicate retains', "expect((int) $f->database->query('SELECT count(*) FROM enqueue WHERE delivery_id IS NULL AND delayed_until > EXTRACT(EPOCH FROM clock_timestamp())')->fetchColumn())->toBe(1);"),
    ]
    # A separate physical Database connection models the pre-PR initializer defect.
    separate = r'''
        $separate = new \Tempest\Database\Connection\PDOConnection(new \App\Integration\OperationsConnectionConfig($container->get(OperationsConfig::class)));
        $separate->connect();
        $separate->prepare('SET search_path TO "' . $d['schema'] . '"')->execute();
        $database = new \Tempest\Database\GenericDatabase($separate, new \Tempest\Database\Transactions\GenericTransactionManager($separate), $container->get(\Tempest\Mapper\SerializerFactory::class), $container->get(\Tempest\EventBus\EventBus::class), new \App\Integration\OperationsConnectionConfig($container->get(OperationsConfig::class)));
'''
    controls.append(('split-database-connection', 'tests/Support/Ecotone/delivery-process.php',
                     '$database = $container->get(OperationsDatabase::class)->connect();',
                     '$database = $container->get(OperationsDatabase::class)->connect();' + separate,
                     'normal Tempest resolution shares', 'expect($result)->toBe(['))
    controls = [(*control, TEST) for control in controls]
    controls.append(('native-priority', 'vendor/ecotone/dbal/src/DbalOutboundChannelAdapter.php',
                     '$messageToSend->setPriority($outboundMessage->getPriority());', '// deliberate native priority loss',
                     'higher-priority', "expect($next['job_id'])->toBe($targetJob);", 'tests/Acceptance/Operations/WorkAdmissionTest.php'))
    controls.append(('reverse-queue-order', 'vendor/ecotone/dbal/src/Connection/DbalConsumerHelperTrait.php',
                     "->addOrderBy('priority', 'asc')", "->addOrderBy('priority', 'desc')",
                     'native delivery reordering', "expect($f->rows('SELECT job_id FROM probe_effects ORDER BY sequence'))->toBe([['job_id' => $second], ['job_id' => $first]]);", 'tests/Acceptance/OperationsDelivery/DurableDeliveryTest.php'))
    output = ROOT / 'tmp/ecotone-native-negative'
    output.mkdir(parents=True, exist_ok=True)
    lock = (output / 'verifier.lock').open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    manifest = {'controls': []}
    baseline_dir = Path(tempfile.mkdtemp(prefix='baseline-', dir=output))
    code, xml, _ = run_test(baseline_dir)
    if code != 0 or len(ET.fromstring(xml).findall('.//testcase')) != 10:
        raise RuntimeError('A passing ten-contract baseline is mandatory.')
    manifest['baseline'] = '10 passed'
    for name, path, old, new, test, assertion, test_path in controls:
        if abort:
            raise InterruptedError('Stopped after bounded cleanup.')
        spec = (ROOT / test_path).read_text()
        test_start = spec.index("test('" + next(n for n in [line.split("test('")[1].split("', function")[0] for line in spec.splitlines() if line.startswith("test('")] if test in n))
        line = spec[:spec.index(assertion, test_start)].count('\n') + 1
        location = f'{test_path}:{line}'
        folder = Path(tempfile.mkdtemp(prefix=name + '-', dir=output))
        def observe():
            code, xml, events = run_test(folder, test, test_path)
            detected = classify(xml, code, test, location, events)
            manifest['controls'].append({'id': name, 'result': 'detected' if detected else 'verification error', 'assertion': location, 'observation': re.search(r'Failed asserting[^\n]*', ET.fromstring(xml).find('.//failure').text or '').group(0) if detected else 'Unexpected result; inspect private logs.'})
            print(name, manifest['controls'][-1]['result'], flush=True)
        mutated(path, lambda source: replace_once(source, old, new), observe, folder)
        manifest['controls'][-1]['source_restored'] = True
    final_dir = Path(tempfile.mkdtemp(prefix='restored-', dir=output))
    code, xml, _ = run_test(final_dir)
    manifest['restored'] = '10 passed' if code == 0 else 'failed'
    (ROOT / 'docs/evaluations/ecotone-native-negative.json').write_text(json.dumps(manifest, indent=2) + '\n')
    if code or any(c['result'] != 'detected' for c in manifest['controls']):
        raise RuntimeError('Negative verification failed; see private logs and retained manifest.')


if __name__ == '__main__':
    main()
