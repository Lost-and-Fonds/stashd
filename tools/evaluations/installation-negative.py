#!/usr/bin/env python3
"""Prove nesting assertions detect premature physical commit and split connections."""
import importlib.util
import json
import fcntl
import signal
import subprocess
from pathlib import Path
import tempfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('native_negative', ROOT / 'tools/evaluations/native-negative.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
TEST = 'tests/Acceptance/EcotoneInstallation/InstallationTest.php'
SELECTED = 'outer commit boundaries'


def main():
    """Restore exact source after each fault and reject every unrelated failure."""
    helper.self_test()
    if subprocess.check_output(['git', 'branch', '--show-current'], cwd=ROOT, text=True).strip() != 'spike/ecotone-evaluation':
        raise RuntimeError('This verifier is restricted to the evaluation branch.')
    signal.signal(signal.SIGINT, helper.stop)
    signal.signal(signal.SIGTERM, helper.stop)
    lock_path = ROOT / 'tmp/ecotone-native-negative/verifier.lock'
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    lock = lock_path.open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    output = ROOT / 'tmp/ecotone-installation-negative'
    output.mkdir(parents=True, exist_ok=True)
    baseline = Path(tempfile.mkdtemp(prefix='baseline-', dir=output))
    code, _, _ = helper.run_test(baseline, SELECTED, TEST)
    if code:
        raise RuntimeError('A passing nested baseline is required.')
    controls = [
        ('premature-inner-commit', 'app/Operations/JobRuntime.php',
         '$this->dispatchWork($id, 0, $priority);',
         '$this->dispatchWork($id, 0, $priority); $database->getNativeConnection()->commit();'),
        ('separate-physical-connection', 'app/Operations/JobRuntimeInitializer.php',
         '$container->get(SharedOperationsConnection::class)->database(),',
         '(new SharedOperationsConnection($container->get(OperationsConfig::class)))->database(),'),
    ]
    assertion = "expect($result['before'])->toBe(['mutations' => 0, 'jobs' => 0, 'invitations' => 0]);"
    line = (ROOT / TEST).read_text().splitlines().index('            ' + assertion) + 1
    manifest = {'baseline': 'passed', 'controls': []}
    for name, path, old, new in controls:
        if helper.abort:
            raise InterruptedError("Stopped after bounded cleanup.")
        target = ROOT / path
        if target.is_symlink() or target.stat().st_nlink != 1:
            raise RuntimeError('Refusing a shared mutation target.')
        original = target.read_bytes()
        changed = helper.replace_once(original.decode(), old, new).encode()
        folder = Path(tempfile.mkdtemp(prefix=name + '-', dir=output))
        backup = folder / 'original'
        backup.write_bytes(original)
        backup.chmod(0o600)
        try:
            target.write_bytes(changed)
            code, xml, events = helper.run_test(folder, SELECTED, TEST)
            detected = helper.classify(xml, code, SELECTED, f'{TEST}:{line}', events)
            manifest['controls'].append({'id': name, 'result': 'detected' if detected else 'verification error', 'assertion': f'{TEST}:{line}'})
            print(name, manifest['controls'][-1]['result'], flush=True)
        finally:
            if target.read_bytes() != changed:
                raise RuntimeError(f'Concurrent edit: original bytes retained in {backup}')
            target.write_bytes(original)
            backup.unlink()
    restored = Path(tempfile.mkdtemp(prefix='restored-', dir=output))
    code, _, _ = helper.run_test(restored, SELECTED, TEST)
    manifest['restored'] = 'passed' if code == 0 else 'failed'
    (ROOT / 'docs/evaluations/ecotone-installation-negative.json').write_text(json.dumps(manifest, indent=2) + '\n')
    if code or any(c['result'] != 'detected' for c in manifest['controls']):
        raise RuntimeError('Nesting verification failed; inspect retained private JUnit/events.')


if __name__ == '__main__':
    main()
