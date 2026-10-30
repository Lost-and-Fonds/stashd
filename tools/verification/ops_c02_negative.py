#!/usr/bin/env python3
"""Verify the eight OPS-C02 negative controls; never treat setup errors as proof."""

import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
TEST = 'tests/Acceptance/OperationsDelivery/DurableDeliveryTest.php'
DRIVER = 'tests/Support/OperationsDelivery/LocalDurableDeliveryDriver.php'
RUNTIME = 'app/Operations/JobRuntime.php'
SENDER = 'vendor/symfony/messenger/Transport/Sender/OutboxSender.php'
PROBE = 'tests/Support/Operations/ProbeExecutor.php'
FIXTURE = 'tests/Support/RecordsAndArchives/TransactionProjectionFixture.php'
UUID = r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}'
INTERRUPTED = False


def replace_once(source, old, new):
    """Refuse drift instead of applying a mutation at an uncertain location."""
    if source.count(old) != 1:
        raise ValueError('Mutation anchor is absent or ambiguous')
    return source.replace(old, new)


def split_transaction(source):
    """Remove only Work admission's shared transaction, leaving its real writes."""
    a = source.index('    public function admitWork(')
    b = source.index('    private function dispatchWork', a)
    section = replace_once(source[a:b],
        'return $this->database->transactional(function (Connection $database) use ($work, $priority): string {',
        '$database = $this->database;\n        return (function () use ($database, $work, $priority): string {')
    return source[:a] + replace_once(section, '        });', '        })();') + source[b:]


# These concrete mutations and witnesses intentionally follow the frozen contracts.
CONTROLS = [
    dict(id='split-admission-transaction', path=RUNTIME, mutate=split_transaction,
         test='outbox insert rejection', line=21, witness='surviving-job',
         invariant='A rejected outbox insert must leave zero persisted Jobs.'),
    dict(id='missing-commit-barrier', path=DRIVER,
         mutate=lambda s: replace_once(s, 'PERFORM pg_sleep(30);', 'NULL;'),
         test='killing admission before commit', anchor="throw new RuntimeException('Child exited before its boundary:", witness='no-barrier',
         invariant='Admission must still be running at the observed COMMIT barrier.'),
    dict(id='ack-without-forwarding', path=SENDER,
         mutate=lambda s: replace_once(s, '$this->target->send(self::restoreSenderStamps($envelope, $outboxStamp));', '// OPS-C02 negative control: skip the destination send.'),
         test='relay crash after forwarding', line=113, witness='missing-forward',
         invariant='A destination envelope must exist before the relay acknowledgment barrier.'),
    dict(id='drop-outbox-on-restart', path=DRIVER,
         mutate=lambda s: replace_once(s, '// Each child opens a new runtime; no connection or process is reused.',
             "if ($action === 'delivery.restart') {\n                $installation = json_decode(file_get_contents($workspace . '/installation.json'), true, flags: JSON_THROW_ON_ERROR);\n                LocalOperationsDriver::connect($installation['descriptor'])->exec(\"DELETE FROM messenger_messages WHERE queue_name = 'outbox'\");\n            }"),
         test='committed Job intent survives', anchor="throw new RuntimeException('Delivery settlement timeout:", witness='lost-intent',
         invariant='A committed queued Job must retain delivery intent and eventually produce its effect.'),
    dict(id='completed-job-can-run-again', path=RUNTIME,
         mutate=lambda s: replace_once(s, "AND (status = 'queued' OR (status = 'running' AND lease_until <= clock_timestamp()))", "AND (status IN ('queued', 'completed') OR (status = 'running' AND lease_until <= clock_timestamp()))"),
         test='completed Job redelivery', line=151, witness='duplicate-effect',
         invariant='Redelivery after persisted completion must not append another probe effect.'),
    dict(id='repeated-effect-visible', path=PROBE,
         mutate=lambda s: replace_once(s, '$effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);', '$effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);\n        $effect->execute([$work->scope, $work->mode, $context->jobId, $work->observation]);'),
         test='committed Job intent survives', line=72, witness='duplicate-effect',
         invariant='The independent effect observer must expose a second INSERT for the same Job.'),
    dict(id='fault-does-not-reject', path=DRIVER,
         mutate=lambda s: replace_once(s, "RAISE EXCEPTION 'OPS-C02 rejected {$queue} write' USING ERRCODE = 'P0001';", 'NULL;'),
         test='outbox insert rejection', anchor='Assert::assertNotSame(0, $result->exitCode);', witness='successful-fault',
         invariant='The armed outbox rejection must make the real admission child fail.'),
    dict(id='implicit-forward-order', path=DRIVER,
         mutate=lambda s: replace_once(s, "foreach ($arguments['job_ids'] as $id)", "foreach (array_reverse($arguments['job_ids']) as $id)"),
         test='changed forwarding order', line=174, witness='reversed-order',
         invariant='Physical destination insertion order must equal the requested reverse order.'),
]


def report(xml, exit_code, count, selected=None):
    """Require a complete PHPUnit report with only the requested test cases."""
    root = ET.fromstring(xml)
    cases = root.findall('.//testcase')
    if len(cases) != count or any(c.find('skipped') is not None for c in cases):
        raise ValueError('Missing, skipped, or unexpected test cases')
    if selected and selected not in cases[0].get('name', ''):
        raise ValueError('Wrong test selected')
    if any(not c.get('file', '').startswith(TEST + '::') for c in cases):
        raise ValueError('Report includes an unrelated test')
    if any(c.tag not in ('failure', 'error') for case in cases for c in case):
        raise ValueError('Unexpected PHPUnit diagnostic')
    problems = [c for case in cases for c in case]
    if exit_code == 0 and problems:
        raise ValueError('Exit status contradicts the PHPUnit report')
    return problems


def classify(control, xml, exit_code, location, output=None, hook_errors=()):
    """Accept only one precise failure plus the values demonstrating its violation."""
    result = dict(result='verification error', evidence={})
    try:
        if hook_errors:
            raise ValueError('Fixture setup or teardown hook failed')
        problems = report(xml, exit_code, 1, control['test'])
        if exit_code == 0 and not problems:
            return dict(result='not detected', evidence={'reason': 'Mutated contract passed.'})
        if exit_code not in (1, 2) or len(problems) != 1:
            raise ValueError('Unexpected exit status or multiple failures')
        problem = problems[0]
        body = problem.text or ''
        # Stack frames follow the failure message. Only the first frame may be
        # the expected assertion; a fixture/teardown failure cannot substitute.
        frames = list(re.finditer(r'(?m)^(?:at )?([^\n]+\.php):(\d+)\s*$', body))
        if not frames or frames[0][1] not in (location[0], str(ROOT / location[0])) or int(frames[0][2]) != location[1]:
            raise ValueError('Failure occurred outside the protected assertion')
        tail = body[frames[0].start():]
        if any(line.strip() and not re.fullmatch(r'(?:at )?[^\n]+\.php:\d+', line.strip()) for line in tail.splitlines()):
            raise ValueError('Additional failure or teardown diagnostic')
        message = body[:frames[0].start()].strip()
        case_name = ET.fromstring(xml).find('.//testcase').get('name', '')
        message = message.removeprefix(case_name)
        observed = output if output is not None else message
        observed = observed.split('\n  at ', 1)[0]
        observed = '\n'.join(line.removeprefix('  ') for line in observed.splitlines())
        # PHPUnit can combine a test failure with a cleanup failure in one node.
        # Reject any extra exception/diagnostic instead of searching through it.
        if re.search(r'TearDown|tearDown|setUp|SQLSTATE|PDOException|Caused by|Additionally|DurableDeliveryTestCase.php', body):
            raise ValueError('Setup, cleanup, or infrastructure diagnostic')
        kind = control['witness']
        evidence = {}
        expected_type = 'PHPUnit\\Framework\\ExpectationFailedException'
        if kind == 'surviving-job':
            if message != 'Failed asserting that two arrays are identical.':
                raise ValueError('Wrong assertion')
            ids = re.findall(UUID, observed)
            if len(ids) != 1 or "'terminal' => false" not in observed or '-Array &0 []' not in observed:
                raise ValueError('No observed surviving queued Job')
            evidence = {'persisted_job_ids': ids, 'terminal': False}
        elif kind == 'missing-forward':
            match = re.fullmatch(r"Failed asserting that an array contains '?(" + UUID + r")'?\.", message)
            if not match:
                raise ValueError('No missing destination Job invitation assertion')
            evidence = {'missing_destination_job_id': match[1]}
        elif kind in ('duplicate-effect', 'reversed-order'):
            if message != 'Failed asserting that two arrays are identical.':
                raise ValueError('Wrong assertion')
            removed = re.findall(r"(?m)^-\s+\d+ => '(" + UUID + r")',?$", observed)
            added = re.findall(r"(?m)^\+\s+\d+ => '(" + UUID + r")',?$", observed)
            common = re.findall(r"(?m)^ \s*\d+ => '(" + UUID + r")',?$", observed)
            if kind == 'duplicate-effect':
                if removed or len(common) != 1 or added != common:
                    raise ValueError('No repeated independently persisted effect')
                evidence = {'expected_effect_job_ids': common, 'actual_effect_job_ids': common + added}
            else:
                if len(removed) != 2 or removed[0] == removed[1] or added != removed[::-1] or common:
                    raise ValueError('No physically reversed pair')
                evidence = {'requested_job_ids': removed, 'physical_destination_job_ids': added}
        elif kind == 'successful-fault':
            if message != 'Failed asserting that 0 is not identical to 0.':
                raise ValueError('Admission did not unexpectedly succeed')
            evidence = {'admission_child_exit': 0}
        elif kind == 'no-barrier':
            expected_type = 'RuntimeException'
            if message != 'RuntimeException: Child exited before its boundary: {"killed":false,"exit":0,"error":""}':
                raise ValueError('Child did not exit successfully before the barrier')
            evidence = {'child_killed': False, 'child_exit': 0, 'barrier_reached': False}
        elif kind == 'lost-intent':
            expected_type = 'RuntimeException'
            prefix = 'RuntimeException: Delivery settlement timeout: '
            if not message.startswith(prefix):
                raise ValueError('Not a settlement diagnostic')
            state = json.loads(message[len(prefix):])
            if (set(state) != {'jobs', 'envelopes', 'effects'} or state['envelopes'] != 0 or state['effects'] != 0
                    or len(state['jobs']) != 1 or set(state['jobs'][0]) != {'id', 'status'}
                    or state['jobs'][0]['status'] != 'queued' or not re.fullmatch(UUID, state['jobs'][0]['id'])):
                raise ValueError('No independently observed stranded queued Job')
            evidence = state
        if problem.get('type') != expected_type:
            raise ValueError('Unexpected exception class')
        # Only allowlisted observations enter the attachable artifact. Raw test
        # output can contain credentials, so it is never copied into evidence.
        return dict(result='detected', evidence=evidence,
                    assertion={'file': location[0], 'line': location[1]},
                    supporting_output=json.dumps(evidence, sort_keys=True))
    except (ValueError, ET.ParseError, KeyError, TypeError) as error:
        result['evidence'] = {'reason': str(error) if type(error) is ValueError else 'Malformed structured report'}
        return result


def digest(data):
    """Hash source bytes without including their content in public evidence."""
    return hashlib.sha256(data).hexdigest()


def git(*args, cwd=ROOT):
    """Read repository state without changing another worktree."""
    return subprocess.check_output(['git', *args], cwd=cwd)


def snapshot():
    """Include all worktrees, staged changes, and existing untracked file bytes."""
    states = []
    for line in git('worktree', 'list', '--porcelain').decode().splitlines():
        if not line.startswith('worktree '):
            continue
        root = Path(line[9:])
        names = git('ls-files', '-m', '-o', '--exclude-standard', '-z', cwd=root).decode().split('\0')
        states.append(dict(status=digest(git('status', '--porcelain=v1', '-z', cwd=root)),
                           diff=digest(git('diff', 'HEAD', '--binary', cwd=root)),
                           files={name: digest((root / name).read_bytes()) for name in names if name and (root / name).is_file()}))
    return states


def run_test(directory, selected=None):
    """Use only the canonical wrapper; defer interruption until fixture cleanup."""
    xml_path = directory / 'junit.xml'
    events_path = directory / 'events.txt'
    xml_path.unlink(missing_ok=True)
    events_path.unlink(missing_ok=True)
    command = ['./bin/test.sh', 'tests/Acceptance/OperationsDelivery', '--compact', '--colors=never', '--log-junit=' + str(xml_path.relative_to(ROOT)), '--log-events-text=' + str(events_path.relative_to(ROOT))]
    if selected:
        command.append('--filter=' + selected)
    try:
        # Never retain raw output: unexpected exceptions may contain local secrets.
        with tempfile.TemporaryFile() as output:
            process = subprocess.Popen(command, cwd=ROOT, env={**os.environ, 'STASHD_TEST_PROCESSES': '1'}, stdout=output, stderr=subprocess.STDOUT)
            try:
                code = process.wait(timeout=90)
            except subprocess.TimeoutExpired:
                process.terminate()
                process.wait(timeout=10)
                raise ValueError('Canonical test wrapper exceeded its deadline') from None
            output.seek(0)
            captured = output.read().decode(errors='replace')
        if not xml_path.is_file():
            raise ValueError('Canonical wrapper produced no structured report')
        xml = xml_path.read_text()
        events = events_path.read_text()
        if 'Test Runner Finished' not in events:
            raise ValueError('Incomplete PHPUnit event stream')
        hook_errors = [line.split(' (', 1)[0] for line in events.splitlines()
                       if 'Method Errored (' in line or line.startswith('Test Preparation Failed (')]
        return xml, code, captured, hook_errors
    finally:
        xml_path.unlink(missing_ok=True)
        events_path.unlink(missing_ok=True)


def location(control):
    """Find driver assertions by unique anchors; frozen acceptance lines stay fixed."""
    if 'line' in control:
        return TEST, control['line']
    source = (ROOT / DRIVER).read_text()
    if source.count(control['anchor']) != 1:
        raise ValueError('Diagnostic anchor is absent or ambiguous')
    return DRIVER, source[:source.index(control['anchor'])].count('\n') + 1


def mutated(path, transform, action, backups):
    """Restore exact pre-existing bytes on exceptions and deferred signals."""
    target = ROOT / path
    if target.is_symlink() or target.resolve() != target or target.stat().st_nlink != 1:
        raise ValueError('Mutation target is shared or linked')
    original = target.read_bytes()
    replacement = transform(original.decode()).encode()
    backup = backups / (digest(path.encode()) + '.original')
    backup.write_bytes(original)
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


def interrupted(signum, frame):
    """Let the bounded test finish its teardown before restoring and stopping."""
    global INTERRUPTED
    INTERRUPTED = True
    print('Interruption requested; finishing bounded fixture cleanup before restoration.', flush=True)


def main():
    """Run baseline, live classifier regression, eight controls, and restored proof."""
    global INTERRUPTED
    os.umask(0o077)
    output = ROOT / 'tmp/ops-c02-verification'
    output.mkdir(parents=True, exist_ok=True)
    if output.resolve() != output or any(output.glob('*.original')):
        raise ValueError('Linked evidence directory or unrecovered source backup; inspect before retrying')
    manifest = {'schema': 1, 'baseline': 'verification error', 'fixture_regression': 'verification error', 'controls': [{k: c[k] for k in ('id', 'path', 'test', 'invariant')} | {'result': 'verification error', 'evidence': {'reason': 'Not run.'}} for c in CONTROLS], 'restored_contracts': 'verification error'}
    signal.signal(signal.SIGINT, interrupted)
    signal.signal(signal.SIGTERM, interrupted)
    with (output / 'runner.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        before = snapshot()
        targets = {c['path'] for c in CONTROLS} | {FIXTURE, TEST, 'tools/verification/ops_c02_negative.py', 'tools/verification/test_ops_c02_negative.py'}
        hashes = {p: digest((ROOT / p).read_bytes()) for p in sorted(targets)}
        try:
            xml, code, _, hooks = run_test(output)
            if report(xml, code, 7) or code or hooks:
                raise ValueError('Unmodified seven-contract baseline failed')
            manifest['baseline'] = 'passed'
            manifest['baseline_assertions'] = sum(int(c.get('assertions', 0)) for c in ET.fromstring(xml).findall('.//testcase'))
            if INTERRUPTED:
                raise ValueError('Interrupted after fixture cleanup')
            print('Baseline: seven contracts passed.', flush=True)
            if (ROOT / 'tmp/ops-c02-intentionally-missing-admin.json').exists():
                raise ValueError('Missing-fixture regression path already exists')
            def regression():
                xml, code, captured, hooks = run_test(output, CONTROLS[0]['test'])
                result = classify(CONTROLS[0], xml, code, location(CONTROLS[0]), captured, hooks)
                if result['result'] != 'verification error' or 'An explicit private local projection test-admin configuration is required.' not in xml:
                    raise ValueError('Fixture counterexample was not rejected for its setup failure')
                result['expected_result'] = 'verification error'
                result['supporting_output'] = 'RuntimeException: An explicit private local projection test-admin configuration is required.'
                return result
            manifest['fixture_regression'] = mutated(FIXTURE,
                lambda s: replace_once(s, "'/tmp/transaction-projection-admin.json'", "'/tmp/ops-c02-intentionally-missing-admin.json'"), regression, output)
            print('Fixture counterexample: correctly rejected as verification error.', flush=True)
            for index, control in enumerate(CONTROLS):
                if INTERRUPTED:
                    raise ValueError('Interrupted after fixture cleanup')
                def verify_control():
                    xml, code, captured, hooks = run_test(output, control['test'])
                    return classify(control, xml, code, location(control), captured, hooks)
                result = mutated(control['path'], control['mutate'], verify_control, output)
                manifest['controls'][index].update(result)
                print(control['id'] + ': ' + result['result'], flush=True)
            if INTERRUPTED:
                raise ValueError('Interrupted after fixture cleanup')
            xml, code, _, hooks = run_test(output)
            if report(xml, code, 7) or code or hooks:
                raise ValueError('Restored seven-contract suite failed')
            manifest['restored_contracts'] = 'passed'
            manifest['restored_assertions'] = sum(int(c.get('assertions', 0)) for c in ET.fromstring(xml).findall('.//testcase'))
        except Exception:
            # Deliberately omit exception text: an unexpected database or wrapper
            # error is private diagnostic material, never attachable evidence.
            manifest['verification_error'] = 'Verification stopped; prerequisite, mutation, report, or integrity check failed.'
        finally:
            manifest['source_hashes_before'] = hashes
            manifest['source_hashes_after'] = {p: digest((ROOT / p).read_bytes()) for p in sorted(targets)}
            manifest['source_integrity'] = manifest['source_hashes_after'] == hashes
            after = snapshot()
            manifest['worktrees_preserved'] = after == before
            manifest['worktree_status_hashes_before'] = [s['status'] for s in before]
            manifest['worktree_status_hashes_after'] = [s['status'] for s in after]
            manifest['overall'] = 'passed' if (manifest['baseline'] == 'passed' and manifest['restored_contracts'] == 'passed'
                and isinstance(manifest['fixture_regression'], dict) and manifest['fixture_regression']['result'] == 'verification error'
                and len(manifest['controls']) == 8 and all(c['result'] == 'detected' for c in manifest['controls'])
                and manifest['source_integrity'] and manifest['worktrees_preserved'] and 'verification_error' not in manifest) else 'failed'
            (output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
            print('Overall: ' + manifest['overall'] + '; sanitized artifact: tmp/ops-c02-verification/manifest.json', flush=True)
    return 0 if manifest['overall'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())
