"""Regression checks for native OPS-C02 failure classification and restoration."""

import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
import xml.etree.ElementTree as ET

import ops_c02_negative as verifier


class NegativeControlTests(unittest.TestCase):
    """Accept named invariant failures; reject infrastructure and cleanup errors."""

    def report(self, text, failure_type='PHPUnit\\Framework\\ExpectationFailedException'):
        root = ET.Element('testsuite')
        case = ET.SubElement(root, 'testcase', name='case')
        ET.SubElement(case, 'failure', type=failure_type).text = text
        return ET.tostring(root, encoding='unicode')

    def check(self, xml, code=1, events=''):
        return verifier.classify(xml, code, 'case', 'x.php:21', events)

    def test_specific_invariant_failure_is_detected(self):
        self.assertTrue(self.check(self.report('Failed asserting that 1 is identical to 0.\nx.php:21\n')))

    def test_fixture_setup_counterexample_is_rejected(self):
        self.assertFalse(self.check(self.report('RuntimeException: Missing fixture administrator.\nFailed asserting that 1 is identical to 0.\nfixture.php:187\n')))

    def test_extra_diagnostics_and_wrong_first_frame_are_rejected(self):
        for text in ['Failed asserting\nfixture.php:187\nx.php:21\n',
                     'Failed asserting\nx.php:21\nRuntimeException: cleanup failed\n',
                     'SQLSTATE: unavailable\nFailed asserting\nx.php:21\n']:
            self.assertFalse(self.check(self.report(text)))

    def test_hook_error_rejects_an_otherwise_valid_violation(self):
        xml = self.report('Failed asserting\nx.php:21\n')
        self.assertFalse(self.check(xml, events='After Test Method Errored (cleanup)'))
        self.assertFalse(self.check(xml, events='Test Preparation Failed (setup)'))

    def test_wrong_assertion_and_infrastructure_exit_are_rejected(self):
        self.assertFalse(self.check(self.report('Failed asserting\nx.php:22\n')))
        for code in [0, 2, 124, 137]:
            self.assertFalse(self.check(self.report('Failed asserting\nx.php:21\n'), code))
        self.assertFalse(self.check('<broken'))

    def test_wrong_exception_and_passing_mutant_are_rejected(self):
        self.assertFalse(self.check(self.report('Failed asserting\nx.php:21\n', 'RuntimeException')))
        self.assertFalse(self.check('<testsuite><testcase name="case"/></testsuite>', 0))

    def test_multiple_problems_or_tests_are_rejected(self):
        xml = self.report('Failed asserting\nx.php:21\n')
        root = ET.fromstring(xml)
        ET.SubElement(root.find('testcase'), 'error').text = 'cleanup'
        self.assertFalse(self.check(ET.tostring(root, encoding='unicode')))
        root = ET.fromstring(xml)
        ET.SubElement(root, 'testcase', name='unrelated')
        self.assertFalse(self.check(ET.tostring(root, encoding='unicode')))

    def test_signal_waits_for_action_then_restores_source(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'source.php'
            target.write_bytes(b'original bytes')
            with patch.object(verifier, 'ROOT', root), patch.object(verifier, 'abort', False):
                verifier.mutated('source.php', lambda s: s + ' mutation', lambda: verifier.stop(15, None), root)
                self.assertTrue(verifier.abort)
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

    def test_mutation_rejects_ambiguous_anchors_and_shared_targets(self):
        with self.assertRaises(ValueError):
            verifier.replace_once('anchor anchor', 'anchor', 'replacement')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / 'source.php'
            target.write_text('original')
            (root / 'link.php').symlink_to(target)
            with patch.object(verifier, 'ROOT', root), self.assertRaises(ValueError):
                verifier.mutated('link.php', lambda s: s + 'mutation', lambda: None, root)


if __name__ == '__main__':
    unittest.main()
