"""Regression checks for the focused OPS-C02 failure classifier and restoration."""

import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import xml.etree.ElementTree as ET

import ops_c02_negative as verifier


class NegativeControlTests(unittest.TestCase):
    """Reject unrelated failures even when a contract and its expected diff appear."""

    def report(self, text, failure_type='PHPUnit\\Framework\\ExpectationFailedException'):
        root = ET.Element('testsuites')
        case = ET.SubElement(root, 'testcase', name=verifier.CONTROLS[0]['test'], file=verifier.TEST + '::test')
        ET.SubElement(case, 'failure', type=failure_type).text = text
        return ET.tostring(root, encoding='unicode')

    def test_fixture_setup_counterexample_is_verification_error(self):
        xml = self.report('RuntimeException: An explicit private local projection test-admin configuration is required.\n'
                          'Failed asserting that 1 is identical to 0.\n\n'
                          'tests/Support/RecordsAndArchives/TransactionProbe.php:187\n')
        self.assertEqual('verification error', verifier.classify(verifier.CONTROLS[0], xml, 1, (verifier.TEST, 21))['result'])

    def test_specific_surviving_job_is_detected(self):
        job = '00000000-0000-0000-0000-000000000001'
        text = ("Failed asserting that two arrays are identical.\n-Array &0 []\n+Array &0 [\n"
                f"+    0 => Array &1 [\n+        'id' => '{job}',\n+        'terminal' => false,\n+    ],\n+]\n\n{verifier.TEST}:21\n")
        junit = self.report('Failed asserting that two arrays are identical.\n\n' + verifier.TEST + ':21\n')
        result = verifier.classify(verifier.CONTROLS[0], junit, 1, (verifier.TEST, 21), text)
        self.assertEqual('detected', result['result'])
        self.assertEqual([job], result['evidence']['persisted_job_ids'])
        # An expected violation accompanied by a cleanup exception is not proof.
        combined = 'Failed asserting that two arrays are identical.\n\n' + verifier.TEST + ':21\ntests/Support/OperationsDelivery/DurableDeliveryTestCase.php:31\n'
        self.assertEqual('verification error', verifier.classify(verifier.CONTROLS[0], self.report(combined), 1, (verifier.TEST, 21), text)['result'])
        extra = 'Failed asserting that two arrays are identical.\n\n' + verifier.TEST + ':21\nRuntimeException: unrelated failure\n'
        self.assertEqual('verification error', verifier.classify(verifier.CONTROLS[0], self.report(extra), 1, (verifier.TEST, 21), text)['result'])

    def test_hook_error_rejects_an_otherwise_valid_violation(self):
        control = verifier.CONTROLS[6]
        xml = self.report('Failed asserting that 0 is not identical to 0.\n\n' + verifier.DRIVER + ':83\n')
        result = verifier.classify(control, xml, 1, (verifier.DRIVER, 83), hook_errors=['After Test Method Errored'])
        self.assertEqual('verification error', result['result'])

    def test_wrong_assertion_and_infrastructure_exit_are_errors(self):
        xml = self.report('Failed asserting that two arrays are identical.\n\n' + verifier.TEST + ':22\n')
        for code in (1, 124, 137):
            self.assertEqual('verification error', verifier.classify(verifier.CONTROLS[0], xml, code, (verifier.TEST, 21))['result'])
        self.assertEqual('verification error', verifier.classify(verifier.CONTROLS[0], '<broken', 1, (verifier.TEST, 21))['result'])

    def test_passing_mutant_is_not_detected(self):
        root = ET.Element('testsuites')
        ET.SubElement(root, 'testcase', name=verifier.CONTROLS[0]['test'], file=verifier.TEST + '::test')
        result = verifier.classify(verifier.CONTROLS[0], ET.tostring(root, encoding='unicode'), 0, (verifier.TEST, 21))
        self.assertEqual('not detected', result['result'])

    def test_signal_waits_for_action_then_restores_source(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'source.php'
            target.write_bytes(b'original bytes')
            with patch.object(verifier, 'ROOT', root), patch.object(verifier, 'INTERRUPTED', False), patch('builtins.print'):
                verifier.mutated('source.php', lambda s: s + ' mutation', lambda: verifier.interrupted(15, None), root)
                self.assertTrue(verifier.INTERRUPTED)
            self.assertEqual(b'original bytes', target.read_bytes())

    def test_exception_restores_preexisting_uncommitted_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'source.php'
            target.write_bytes(b'preexisting uncommitted work\n')
            def fail():
                raise RuntimeError('controlled interruption')
            with patch.object(verifier, 'ROOT', root):
                with self.assertRaises(RuntimeError):
                    verifier.mutated('source.php', lambda s: s + 'mutation\n', fail, root)
            self.assertEqual(b'preexisting uncommitted work\n', target.read_bytes())
            self.assertEqual([target], list(root.iterdir()))


if __name__ == '__main__':
    unittest.main()
