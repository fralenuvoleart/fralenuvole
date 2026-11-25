#!/usr/bin/env python3
"""Offline stdlib unit tests for tools/audit/ deterministic scripts."""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import audit_collect
import audit_render
import audit_verify


class TestAuditDeterministicPipeline(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self.tmp.name) / "repo"
        (self.repo / "core" / "cache").mkdir(parents=True)
        (self.repo / "config").mkdir(parents=True)
        (self.repo / "core" / "cache" / "sample.php").write_text(
            "<?php\nfunction frl_sample_ok() {\n\t$x = 1;\n\treturn $x;\n}\n",
            encoding="utf-8",
        )
        (self.repo / "config" / "config-options.php").write_text(
            "<?php\n$opts = array(\n\t'debugger_ok' => 0,\n);\n",
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def test_discover_and_slice(self) -> None:
        files = audit_collect.discover_php_files(self.repo)
        self.assertEqual(files, ["config/config-options.php", "core/cache/sample.php"])
        slices = audit_collect.assign_slices(files, ["core/cache"])
        self.assertEqual(slices, {"core/cache": ["core/cache/sample.php"]})

    def test_system_pattern_sp1_catches_debug_prefix(self) -> None:
        (self.repo / "config" / "config-options-bad.php").write_text(
            "<?php\n$opts = array('debug_hidden' => 0);\n",
            encoding="utf-8",
        )
        files = audit_collect.discover_php_files(self.repo)
        checks = {c["id"]: c for c in audit_collect.check_system_patterns(self.repo, files)}
        self.assertFalse(checks["SP1_debug_option_prefix"]["passed"])
        self.assertEqual(len(checks["SP1_debug_option_prefix"]["hits"]), 1)

    def test_verify_d2_rejects_hallucinated_quote_and_accepts_real_quote(self) -> None:
        collect_data = {"phpcs": {"status": "unavailable"}}
        bad = [
            {
                "slice": "core/cache",
                "verified_clean": [],
                "findings": [
                    {
                        "id": "C-1",
                        "category": "bug",
                        "severity": "medium",
                        "file": "core/cache/sample.php",
                        "line_start": 2,
                        "line_end": 4,
                        "evidence_quote": "this line does not exist",
                        "mechanism": "Triggers on every request when called.",
                        "regression_risk": "safe",
                        "proposed_fix": "Fix the return value.",
                        "verify_method": "static",
                    }
                ],
            }
        ]
        gate_bad, _ = audit_verify.verify_slice_findings(self.repo, collect_data, bad)
        self.assertFalse(gate_bad["passed"])
        self.assertEqual(gate_bad["failures"][0]["check_id"], "D2")

        good = [
            {
                "slice": "core/cache",
                "verified_clean": ["No race condition"],
                "findings": [
                    {
                        "id": "C-1",
                        "category": "kiss",
                        "severity": "low",
                        "file": "core/cache/sample.php",
                        "line_start": 2,
                        "line_end": 4,
                        "evidence_quote": "$x = 1;",
                        "mechanism": "Unnecessary temporary variable.",
                        "regression_risk": "safe",
                        "proposed_fix": "Return 1 directly.",
                        "verify_method": "static",
                    }
                ],
            }
        ]
        gate_good, verified = audit_verify.verify_slice_findings(self.repo, collect_data, good)
        self.assertTrue(gate_good["passed"])
        self.assertEqual(len(verified["findings"]), 1)

    def test_verify_d4_rejects_phpcs_excluded_yoda_nit(self) -> None:
        collect_data = {"phpcs": {"status": "unavailable"}}
        yoda = [
            {
                "slice": "core/cache",
                "verified_clean": [],
                "findings": [
                    {
                        "id": "C-2",
                        "category": "maintainability",
                        "severity": "low",
                        "file": "core/cache/sample.php",
                        "line_start": 3,
                        "line_end": 3,
                        "evidence_quote": "$x = 1;",
                        "mechanism": "Missing Yoda condition style.",
                        "regression_risk": "safe",
                        "proposed_fix": "Use Yoda condition.",
                        "verify_method": "static",
                    }
                ],
            }
        ]
        gate, _ = audit_verify.verify_slice_findings(self.repo, collect_data, yoda)
        self.assertFalse(gate["passed"])
        self.assertEqual(gate["failures"][0]["check_id"], "D4")

    def test_render_and_trace(self) -> None:
        collect_data = {
            "generated_at": "2026-11-25T00:00:00Z",
            "shipped_php_files": 2,
            "total_loc": 10,
            "hot_function_count": 0,
            "phpcs_ignore_total": 0,
            "php_lint": {"checked_files": 2, "error_count": 0, "passed": True},
            "phpcs": {"status": "ok", "errors": 0, "warnings": 0, "files_with_issues": 0},
            "phpstan": {"status": "unconfigured", "reason": "no config"},
            "tests": {"invariants": {"status": "passed", "summary": ["All invariant tests passed."]}},
            "system_pattern_checks": [{"id": "SP1_debug_option_prefix", "passed": True, "rule": "ok"}],
        }
        verified = {"slices": ["core/cache"], "verified_clean": {"core/cache": ["Clean"]}, "findings": []}
        synthesis = {"verdict": "**All clean.**", "top_actions": [], "architectural_upgrades": [], "ruled_out": []}
        checker = {"round": 1, "verdict": "PASS", "checked_count": 0, "rejected_findings": [], "pass_summary": "OK"}
        det_gate = {"passed": True, "failure_count": 0}
        md = audit_render.render_report(collect_data, verified, synthesis, checker, det_gate)
        self.assertIn("**All clean.**", md)
        self.assertNotIn("****All clean.", md)
        trace_file = Path(self.tmp.name) / "trace.jsonl"
        rec = audit_render.write_trace([trace_file], "test-run", verified, det_gate, checker)
        self.assertEqual(rec["checker_verdict"], "PASS")
        self.assertEqual(json.loads(trace_file.read_text(encoding="utf-8").strip())["run_id"], "test-run")


if __name__ == "__main__":
    unittest.main()
