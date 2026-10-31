#!/usr/bin/env python3
"""Prove nesting assertions detect premature physical commit and split connections."""
import argparse
import importlib.util
import json
import fcntl
import signal
import subprocess
from pathlib import Path
import tempfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('native_negative', ROOT / 'tools/evaluations/native-negative.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
TEST = 'tests/Acceptance/EcotoneInstallation/InstallationTest.php'
SELECTED = 'outer commit boundaries'


def main():
    """Restore exact source after each fault and reject every unrelated failure."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--injected', action='store_true', help='Verify the isolated upstream Ecotone injection patch.')
    injected = parser.parse_args().injected
    test = TEST.replace('InstallationTest.php', 'InjectedIntegrationTest.php') if injected else TEST
    selected = 'nested commit boundaries' if injected else SELECTED
    helper.self_test()
    if subprocess.check_output(['git', 'branch', '--show-current'], cwd=ROOT, text=True).strip() != 'spike/ecotone-evaluation':
        raise RuntimeError('This verifier is restricted to the evaluation branch.')
    signal.signal(signal.SIGINT, helper.stop)
    signal.signal(signal.SIGTERM, helper.stop)
    lock_path = ROOT / 'tmp/ecotone-native-negative/verifier.lock'
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    lock = lock_path.open('w')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    suffix = 'ecotone-injected-negative' if injected else 'ecotone-installation-negative'
    output = ROOT / 'tmp' / suffix
    output.mkdir(parents=True, exist_ok=True)
    baseline = Path(tempfile.mkdtemp(prefix='baseline-', dir=output))
    code, xml, _ = helper.run_test(baseline, selected, test)
    if code or len(ET.fromstring(xml).findall('.//testcase')) != 1:
        raise RuntimeError('A passing nested baseline is required.')
    controls = [
        ('premature-inner-commit', 'app/Operations/JobRuntime.php',
         '$this->dispatchWork($id, 0, $priority);',
         '$this->dispatchWork($id, 0, $priority); $database->getNativeConnection()->commit();'),
        ('separate-physical-connection', 'app/Operations/JobRuntimeInitializer.php',
         '$container->get(SharedOperationsConnection::class)->database(),',
         '(new SharedOperationsConnection($container->get(OperationsConfig::class)))->database(),'),
    ]
    if injected:
        controls[1] = ('separate-physical-connection', 'tmp/official-tempest-vendor/ecotone/tempest/src/Config/TempestConnectionResolver.php',
                       'return DbalConnection::create($injected->database());',
                       'return DbalConnection::create(DbalBackedConnection::fromConfiguration(GenericContainer::instance()->get(DatabaseConfig::class))->database());')
        controls.append(('bypass-dbal-nesting', 'tmp/official-tempest-vendor/ecotone/tempest/Api/DbalBackedConnection.php',
                         '$this->database->beginTransaction();', '$this->pdo()->beginTransaction();'))
    assertion = "expect($result['before'])->toBe(['mutations' => 0, 'jobs' => 0, 'invitations' => 0]);"
    manifest = {'baseline': 'passed', 'controls': []}
    for name, path, old, new in controls:
        if helper.abort:
            raise InterruptedError("Stopped after bounded cleanup.")
        selected_assertion = "expect($result['inner_error'])->toBe($mode === 'inner-failure' ? '23514' : null, $result['inner_error_message'] ?? '');" if name == 'bypass-dbal-nesting' else assertion
        line = (ROOT / test).read_text().splitlines().index('            ' + selected_assertion) + 1
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
            code, xml, events = helper.run_test(folder, selected, test)
            detected = helper.classify(xml, code, selected, f'{test}:{line}', events)
            manifest['controls'].append({'id': name, 'result': 'detected' if detected else 'verification error', 'assertion': f'{test}:{line}'})
            print(name, manifest['controls'][-1]['result'], flush=True)
        finally:
            if target.read_bytes() != changed:
                raise RuntimeError(f'Concurrent edit: original bytes retained in {backup}')
            target.write_bytes(original)
            backup.unlink()
    restored = Path(tempfile.mkdtemp(prefix='restored-', dir=output))
    code, xml, _ = helper.run_test(restored, selected, test)
    if len(ET.fromstring(xml).findall('.//testcase')) != 1:
        raise RuntimeError('Restoration must execute the selected test.')
    manifest['restored'] = 'passed' if code == 0 else 'failed'
    (ROOT / 'docs/evaluations' / (suffix + '.json')).write_text(json.dumps(manifest, indent=2) + '\n')
    if code or any(c['result'] != 'detected' for c in manifest['controls']):
        raise RuntimeError('Nesting verification failed; inspect retained private JUnit/events.')


if __name__ == '__main__':
    main()
