"""Antigravity run_workflow script for deterministic-first fralenuvole codebase audit."""

import json
import subprocess
from datetime import datetime, timezone
from pathlib import Path

REPO_ROOT = Path(WORKSPACES[0]).resolve() if WORKSPACES else Path.cwd().resolve()
SYSTEM_PATTERNS_PATH = Path("/home/francesco/code/knowledge/projects/fralenuvole/systemPatterns.md")
PHPCS_XML_PATH = REPO_ROOT / "phpcs.xml"
REPORTS_BASE = Path("/home/francesco/Documents/Reports/fralenuvole")

SLICE_SCHEMA = {
    "type": "object",
    "required": ["slice", "verified_clean", "findings"],
    "properties": {
        "slice": {"type": "string"},
        "verified_clean": {"type": "array", "items": {"type": "string"}},
        "findings": {
            "type": "array",
            "items": {
                "type": "object",
                "required": [
                    "id",
                    "category",
                    "severity",
                    "file",
                    "line_start",
                    "line_end",
                    "evidence_quote",
                    "mechanism",
                    "regression_risk",
                    "proposed_fix",
                    "verify_method",
                ],
                "properties": {
                    "id": {"type": "string"},
                    "category": {
                        "type": "string",
                        "enum": [
                            "bug",
                            "error",
                            "kiss",
                            "modularity",
                            "maintainability",
                            "performance",
                            "architecture",
                        ],
                    },
                    "severity": {"type": "string", "enum": ["critical", "high", "medium", "low"]},
                    "file": {"type": "string"},
                    "line_start": {"type": "integer"},
                    "line_end": {"type": "integer"},
                    "evidence_quote": {"type": "string"},
                    "mechanism": {"type": "string"},
                    "regression_risk": {"type": "string", "enum": ["safe", "caution", "high"]},
                    "proposed_fix": {"type": "string"},
                    "verify_method": {
                        "type": "string",
                        "enum": ["static", "grep", "executed", "unverifiable_locally"],
                    },
                },
            },
        },
    },
}

SYNTHESIS_SCHEMA = {
    "type": "object",
    "required": ["verdict", "counts_by_category", "top_actions", "architectural_upgrades", "ruled_out"],
    "properties": {
        "verdict": {"type": "string"},
        "counts_by_category": {"type": "object"},
        "top_actions": {
            "type": "array",
            "items": {
                "type": "object",
                "required": [
                    "rank",
                    "title",
                    "finding_ids",
                    "category",
                    "impact",
                    "effort",
                    "regression_risk",
                    "rationale",
                ],
                "properties": {
                    "rank": {"type": "integer"},
                    "title": {"type": "string"},
                    "finding_ids": {"type": "array", "items": {"type": "string"}},
                    "category": {"type": "string"},
                    "impact": {"type": "string", "enum": ["high", "medium", "low"]},
                    "effort": {"type": "string", "enum": ["low", "medium", "high"]},
                    "regression_risk": {"type": "string", "enum": ["safe", "caution", "high"]},
                    "rationale": {"type": "string"},
                },
            },
        },
        "architectural_upgrades": {
            "type": "array",
            "items": {
                "type": "object",
                "required": ["id", "title", "subsystems", "problem", "target_design", "migration_risk", "finding_ids"],
                "properties": {
                    "id": {"type": "string"},
                    "title": {"type": "string"},
                    "subsystems": {"type": "array", "items": {"type": "string"}},
                    "problem": {"type": "string"},
                    "target_design": {"type": "string"},
                    "migration_risk": {"type": "string", "enum": ["low", "medium", "high"]},
                    "finding_ids": {"type": "array", "items": {"type": "string"}},
                },
            },
        },
        "ruled_out": {
            "type": "array",
            "items": {
                "type": "object",
                "required": ["topic", "reason", "reference"],
                "properties": {
                    "topic": {"type": "string"},
                    "reason": {"type": "string"},
                    "reference": {"type": "string"},
                },
            },
        },
    },
}

CHECKER_SCHEMA = {
    "type": "object",
    "required": ["round", "verdict", "checked_count", "rejected_findings", "synthesis_failures", "pass_summary"],
    "properties": {
        "round": {"type": "integer"},
        "verdict": {"type": "string", "enum": ["PASS", "FAIL"]},
        "checked_count": {"type": "integer"},
        "rejected_findings": {
            "type": "array",
            "items": {
                "type": "object",
                "required": ["finding_id", "check_id", "target_agent", "reason", "evidence"],
                "properties": {
                    "finding_id": {"type": "string"},
                    "check_id": {"type": "string", "enum": ["C1", "C2", "C3", "C4", "C5"]},
                    "target_agent": {"type": "string"},
                    "reason": {"type": "string"},
                    "evidence": {"type": "string"},
                },
            },
        },
        "synthesis_failures": {
            "type": "array",
            "items": {
                "type": "object",
                "required": ["check_id", "reason"],
                "properties": {
                    "check_id": {"type": "string", "enum": ["C4", "C5"]},
                    "reason": {"type": "string"},
                },
            },
        },
        "pass_summary": {"type": "string"},
    },
}


def _run_py(script: str, cli_args: list[str], allow_fail: bool = False) -> subprocess.CompletedProcess:
    proc = subprocess.run(
        ["python3", str(REPO_ROOT / "tools" / "audit" / script), *cli_args],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0 and not allow_fail:
        raise RuntimeError(f"{script} failed ({proc.returncode}): {proc.stderr or proc.stdout}")
    return proc


# ---------------------------------------------------------------------------
# Phase 1: Deterministic collection
# ---------------------------------------------------------------------------
phase("1. Deterministic Collect")
run_id = args.get("run_id") or datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
run_dir = Path(args.get("run_dir") or (REPORTS_BASE / "runs" / run_id)).resolve()
slices_arg = args.get("slices", "core/cache,core/debugger")
if isinstance(slices_arg, list):
    slices_arg = ",".join(slices_arg)

collect_args = ["--repo", str(REPO_ROOT), "--out", str(run_dir)]
if slices_arg and slices_arg != "all":
    collect_args.extend(["--slices", slices_arg])

collect_proc = _run_py("audit_collect.py", collect_args)
log(f"collect.json ready: {collect_proc.stdout.strip()}")

collect_data = json.loads((run_dir / "collect.json").read_text(encoding="utf-8"))
slice_input_files = sorted((run_dir / "slice_inputs").glob("*.json"))
slice_inputs = [json.loads(p.read_text(encoding="utf-8")) for p in slice_input_files]
slices_out_dir = run_dir / "slice_findings"
slices_out_dir.mkdir(parents=True, exist_ok=True)


def _auditor_prompt(slice_info: dict, feedback: list | None = None) -> str:
    fb_block = ""
    if feedback:
        fb_block = (
            "\n\nREPAIR REQUIRED — fix ONLY the failing items below (or drop them if invalid) "
            "and return the complete updated slice JSON:\n"
            + json.dumps(feedback, indent=2)
        )
    return (
        f"Audit fralenuvole subsystem slice `{slice_info['slice']}`.\n"
        f"Repo root: {REPO_ROOT}\n"
        f"MANDATORY: First read {SYSTEM_PATTERNS_PATH} so you NEVER flag documented deliberate design choices.\n"
        f"Then read the PHP files in this slice (listed below) using read_file and inspect real call sites.\n"
        f"Focus ONLY on non-mechanical judgment: real bugs/PHP 8 errors, KISS/modularity/maintainability "
        f"hotspots, runtime performance waste, and concrete architectural improvements.\n"
        f"HARD RULES:\n"
        f"- Use exact relative file paths from repo root and exact 1-based line_start/line_end.\n"
        f"- evidence_quote MUST be a verbatim substring copied directly from those exact lines.\n"
        f"- Keep findings high-signal (max 8 findings per slice); list clean checks in verified_clean.\n"
        f"- Prefix finding IDs with `{slice_info['slug'].upper()}-` (e.g. {slice_info['slug'].upper()}-1).\n"
        f"- NEVER duplicate phpcs issues or documented phpcs.xml exclusions.\n"
        f"- Mark regression_risk='caution' or 'high' for any fix touching cache keys, hooks, autoload, or option names.\n"
        f"\nSlice deterministic summary:\n{json.dumps(slice_info, indent=2)}"
        f"{fb_block}"
    )


# ---------------------------------------------------------------------------
# Phase 2: Parallel Subsystem Audit + Deterministic Gate (D1-D5)
# ---------------------------------------------------------------------------
phase("2. Parallel Subsystem Audit")
slice_results = await parallel(
    slice_inputs,
    lambda s: agent(
        _auditor_prompt(s),
        schema=SLICE_SCHEMA,
        role=f"Audit {s['slice']}",
        type_name="research",
        model="flash",
    ),
)

for s, res in zip(slice_inputs, slice_results):
    (slices_out_dir / f"{s['slug']}.json").write_text(json.dumps(res, indent=2) + "\n", encoding="utf-8")

det_proc = _run_py(
    "audit_verify.py",
    [
        "--repo", str(REPO_ROOT),
        "--collect", str(run_dir / "collect.json"),
        "--slices-dir", str(slices_out_dir),
        "--out-dir", str(run_dir),
    ],
    allow_fail=True,
)
det_gate = json.loads((run_dir / "deterministic_gate.json").read_text(encoding="utf-8"))
log(f"Deterministic gate pass 1: passed={det_gate['passed']} accepted={det_gate['total_accepted']} failures={det_gate['failure_count']}")

# One bounded deterministic repair pass if D1-D5 failed
if not det_gate["passed"]:
    phase("2b. Deterministic Gate Repair")
    by_slice: dict[str, list] = {}
    for f in det_gate["failures"]:
        by_slice.setdefault(f.get("slice", ""), []).append(f)
    failing_slices = [s for s in slice_inputs if s["slice"] in by_slice]
    if failing_slices:
        repaired = await parallel(
            failing_slices,
            lambda s: agent(
                _auditor_prompt(s, feedback=by_slice[s["slice"]]),
                schema=SLICE_SCHEMA,
                role=f"Repair {s['slice']} (D1-D5)",
                type_name="research",
                model="flash",
            ),
        )
        for s, res in zip(failing_slices, repaired):
            (slices_out_dir / f"{s['slug']}.json").write_text(json.dumps(res, indent=2) + "\n", encoding="utf-8")
        _run_py(
            "audit_verify.py",
            [
                "--repo", str(REPO_ROOT),
                "--collect", str(run_dir / "collect.json"),
                "--slices-dir", str(slices_out_dir),
                "--out-dir", str(run_dir),
            ],
            allow_fail=True,
        )
        det_gate = json.loads((run_dir / "deterministic_gate.json").read_text(encoding="utf-8"))
        log(f"Deterministic gate pass 2: passed={det_gate['passed']} accepted={det_gate['total_accepted']} failures={det_gate['failure_count']}")


# ---------------------------------------------------------------------------
# Phase 3: Architect Synthesizer
# ---------------------------------------------------------------------------
async def _run_synthesizer(feedback: list | None = None) -> dict:
    verified_data = json.loads((run_dir / "verified_slices.json").read_text(encoding="utf-8"))
    fb_block = f"\n\nFIX THESE SYNTHESIS FAILURES:\n{json.dumps(feedback, indent=2)}" if feedback else ""
    prompt = (
        f"Synthesize the verified fralenuvole audit findings into a crisp verdict, top 3-5 prioritized actions, "
        f"architectural upgrade roadmap, and ruled-out list.\n"
        f"HARD RULES:\n"
        f"- Reference ONLY finding_ids present in verified_slices.json — NEVER invent new findings.\n"
        f"- counts_by_category MUST exactly match the findings in verified_slices.json.\n"
        f"- Lead verdict with one bold-ready decisive sentence grounded in the real numbers.\n"
        f"\nDeterministic collect summary:\n{json.dumps({k: collect_data[k] for k in ('shipped_php_files', 'total_loc', 'hot_function_count', 'phpcs_ignore_total', 'php_lint', 'tests', 'system_pattern_checks', 'slices')}, indent=2)}\n"
        f"\nVerified slice findings:\n{json.dumps(verified_data, indent=2)}"
        f"{fb_block}"
    )
    res = await agent(
        prompt,
        schema=SYNTHESIS_SCHEMA,
        role="Architect Synthesizer",
        type_name="research",
        model="inherit",
    )
    (run_dir / "synthesis.json").write_text(json.dumps(res, indent=2) + "\n", encoding="utf-8")
    return res


phase("3. Architect Synthesis")
synthesis = await _run_synthesizer()
log(f"Synthesis complete: {len(synthesis['top_actions'])} actions, {len(synthesis['architectural_upgrades'])} upgrades")


# ---------------------------------------------------------------------------
# Phase 4: Independent Checker Loop (separate context, max 2 rounds)
# ---------------------------------------------------------------------------
checker_verdict = None
for round_num in (1, 2):
    phase(f"4. Independent Checker (Round {round_num})")
    verified_data = json.loads((run_dir / "verified_slices.json").read_text(encoding="utf-8"))
    checker_prompt = (
        f"Independent adversarial audit verification — Round {round_num}.\n"
        f"Repo root: {REPO_ROOT}\n"
        f"Read {SYSTEM_PATTERNS_PATH} and {PHPCS_XML_PATH}, then spot-check EVERY finding's cited file/lines "
        f"with read_file to verify claims against real code.\n"
        f"Evaluate HARD GATE C1-C5:\n"
        f"- C1: No false positives (code actually behaves as claimed at real call sites).\n"
        f"- C2: Zero violations of systemPatterns.md deliberate design rules or phpcs.xml exclusions.\n"
        f"- C3: Accurate regression_risk (any fix touching cache keys, hooks, autoload, or option names must be caution/high).\n"
        f"- C4: Synthesis top_actions and architectural_upgrades strictly cite verified finding_ids and exact counts.\n"
        f"- C5: No unverified runtime claims presented as verified.\n"
        f"Set target_agent to the finding's `slice` value (or `synthesizer` for synthesis issues).\n"
        f"Return verdict='PASS' ONLY if rejected_findings and synthesis_failures are both empty.\n"
        f"\nVerified findings:\n{json.dumps(verified_data, indent=2)}\n"
        f"\nSynthesis:\n{json.dumps(synthesis, indent=2)}"
    )
    checker_verdict = await agent(
        checker_prompt,
        schema=CHECKER_SCHEMA,
        role=f"Independent Checker R{round_num}",
        type_name="research",
        model="inherit",
    )
    checker_verdict["round"] = round_num
    (run_dir / "checker_verdict.json").write_text(json.dumps(checker_verdict, indent=2) + "\n", encoding="utf-8")
    log(
        f"Checker round {round_num}: verdict={checker_verdict['verdict']} "
        f"checked={checker_verdict['checked_count']} "
        f"rejected={len(checker_verdict['rejected_findings'])} "
        f"synth_fail={len(checker_verdict['synthesis_failures'])}"
    )

    if checker_verdict["verdict"] == "PASS":
        break

    if round_num == 2:
        (run_dir / "escalation.json").write_text(json.dumps(checker_verdict, indent=2) + "\n", encoding="utf-8")
        log("Checker still FAIL after round 2 — wrote escalation.json for owner review.")
        break

    # Route exact failing check IDs back to responsible slice auditor(s) and/or synthesizer
    by_slice: dict[str, list] = {}
    for rej in checker_verdict["rejected_findings"]:
        by_slice.setdefault(rej["target_agent"], []).append(rej)

    failing_slices = [s for s in slice_inputs if s["slice"] in by_slice]
    if failing_slices:
        phase("4b. Slice Repair from Checker R1")
        repaired = await parallel(
            failing_slices,
            lambda s: agent(
                _auditor_prompt(s, feedback=by_slice[s["slice"]]),
                schema=SLICE_SCHEMA,
                role=f"Repair {s['slice']} (Checker R1)",
                type_name="research",
                model="flash",
            ),
        )
        for s, res in zip(failing_slices, repaired):
            (slices_out_dir / f"{s['slug']}.json").write_text(json.dumps(res, indent=2) + "\n", encoding="utf-8")
        _run_py(
            "audit_verify.py",
            [
                "--repo", str(REPO_ROOT),
                "--collect", str(run_dir / "collect.json"),
                "--slices-dir", str(slices_out_dir),
                "--out-dir", str(run_dir),
            ],
            allow_fail=True,
        )

    if failing_slices or checker_verdict["synthesis_failures"]:
        phase("4c. Re-Synthesize after Checker R1")
        synthesis = await _run_synthesizer(feedback=checker_verdict["synthesis_failures"] or None)


# ---------------------------------------------------------------------------
# Phase 5: Deterministic Render & Trace
# ---------------------------------------------------------------------------
phase("5. Render Report & Trace")
render_proc = _run_py(
    "audit_render.py",
    [
        "--run-dir", str(run_dir),
        "--global-trace", str(REPORTS_BASE / "run_trace.jsonl"),
    ],
)
log(f"Rendered: {render_proc.stdout.strip()}")
print(
    json.dumps(
        {
            "run_dir": str(run_dir),
            "report_path": str(run_dir / "AUDIT-REPORT.md"),
            "checker_round": checker_verdict["round"],
            "checker_verdict": checker_verdict["verdict"],
            "deterministic_gate_passed": det_gate["passed"],
        },
        indent=2,
    )
)
