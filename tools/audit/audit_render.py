#!/usr/bin/env python3
"""Deterministic Markdown report renderer and trace writer for fralenuvole audit."""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

SEVERITY_ORDER = {"critical": 0, "high": 1, "medium": 2, "low": 3}


def render_report(
    collect_data: dict[str, Any],
    verified: dict[str, Any],
    synthesis: dict[str, Any],
    checker: dict[str, Any],
    det_gate: dict[str, Any],
    escalation: dict[str, Any] | None = None,
) -> str:
    """Render golden-standard Markdown audit report from verified JSON artifacts."""
    findings: list[dict[str, Any]] = verified.get("findings", [])
    findings_sorted = sorted(
        findings,
        key=lambda f: (SEVERITY_ORDER.get(f["severity"], 9), f["file"], f["line_start"]),
    )
    sev_counts = Counter(f["severity"] for f in findings_sorted)
    cat_counts = Counter(f["category"] for f in findings_sorted)

    lines: list[str] = []
    lines.append("# Fralenuvole Codebase Audit Report")
    lines.append("")
    verdict_text = str(synthesis.get("verdict", "Audit completed.")).strip()
    if verdict_text.startswith("**") and verdict_text.endswith("**") and len(verdict_text) > 4:
        verdict_text = verdict_text[2:-2].strip()
    lines.append(f"**{verdict_text}**")
    lines.append("")
    lines.append(
        f"- **Generated:** `{collect_data.get('generated_at', '')}` (UTC)  "
        f"\n- **Scope:** `{', '.join(verified.get('slices', []))}` "
        f"(repo total: {collect_data.get('shipped_php_files', 0)} shipped PHP files, "
        f"{collect_data.get('total_loc', 0):,} LOC)  "
        f"\n- **Independent Checker:** `{checker.get('verdict', 'UNKNOWN')}` "
        f"(round {checker.get('round', 0)}/2, checked {checker.get('checked_count', 0)}, "
        f"rejected {len(checker.get('rejected_findings', []))})"
    )
    lines.append("")

    if escalation:
        lines.append("> [!WARNING]")
        lines.append(
            f"> **Escalated after round {escalation.get('round', 2)}:** "
            f"{len(escalation.get('rejected_findings', []))} finding(s) and "
            f"{len(escalation.get('synthesis_failures', []))} synthesis check(s) require owner review."
        )
        lines.append("")

    # Deterministic gates table (count / status first)
    lines.append("## 1. Deterministic Gates (Repo-Wide)")
    lines.append("")
    lines.append("| Count / Result | Gate | Details |")
    lines.append("|---:|---|---|")
    lint = collect_data.get("php_lint", {})
    lines.append(
        f"| {lint.get('error_count', 0)} errors | `php -l` | "
        f"{lint.get('checked_files', 0)} shipped PHP files checked |"
    )
    phpcs = collect_data.get("phpcs", {})
    if phpcs.get("status") == "ok":
        lines.append(
            f"| {phpcs.get('errors', 0)}E / {phpcs.get('warnings', 0)}W | `vendor/bin/phpcs` | "
            f"{phpcs.get('files_with_issues', 0)} files with issues (respecting `phpcs.xml`) |"
        )
    else:
        lines.append(f"| {phpcs.get('status', 'n/a')} | `vendor/bin/phpcs` | {phpcs.get('reason', '')} |")

    phpstan = collect_data.get("phpstan", {})
    lines.append(
        f"| {phpstan.get('status', 'n/a')} | `vendor/bin/phpstan` | {phpstan.get('reason', 'executed')} |"
    )

    for test_name, test_info in collect_data.get("tests", {}).items():
        summary = " / ".join(test_info.get("summary", [])[-1:])
        lines.append(f"| {test_info.get('status', 'n/a')} | `tests/{test_name}` | {summary} |")

    lines.append(
        f"| {collect_data.get('hot_function_count', 0)} hotspots | Static complexity | "
        f"Functions >80 LOC or nesting depth >4; {collect_data.get('phpcs_ignore_total', 0)} `phpcs:ignore` markers |"
    )
    for sp in collect_data.get("system_pattern_checks", []):
        status = "PASS" if sp.get("passed") else f"FAIL ({len(sp.get('hits', []))})"
        extra = f"columns={sp['column_count']}" if "column_count" in sp else sp.get("rule", "")
        lines.append(f"| {status} | `{sp.get('id')}` | {extra} |")
    lines.append("")

    # Findings summary (count in first column, highest first)
    lines.append("## 2. Verified Findings Summary")
    lines.append("")
    lines.append("| Count | Category |")
    lines.append("|---:|---|")
    for cat, cnt in cat_counts.most_common():
        lines.append(f"| {cnt} | `{cat}` |")
    if not cat_counts:
        lines.append("| 0 | No open findings in audited slices |")
    lines.append("")
    lines.append(
        f"Severity breakdown: critical={sev_counts.get('critical', 0)}, "
        f"high={sev_counts.get('high', 0)}, medium={sev_counts.get('medium', 0)}, "
        f"low={sev_counts.get('low', 0)}."
    )
    lines.append("")

    # Prioritized actions
    lines.append("## 3. Prioritized Actions")
    lines.append("")
    for act in synthesis.get("top_actions", []):
        fids = ", ".join(f"`{x}`" for x in act.get("finding_ids", []))
        lines.append(
            f"{act.get('rank', '-')}. **{act.get('title', '')}** "
            f"(`{act.get('category', '')}` | impact: `{act.get('impact', '')}` | "
            f"effort: `{act.get('effort', '')}` | risk: `{act.get('regression_risk', '')}` | {fids}) — "
            f"{act.get('rationale', '')}"
        )
    lines.append("")

    # Architectural upgrades
    lines.append("## 4. Architectural Upgrade Opportunities")
    lines.append("")
    upgrades = synthesis.get("architectural_upgrades", [])
    if upgrades:
        for up in upgrades:
            fids = ", ".join(f"`{x}`" for x in up.get("finding_ids", []))
            lines.append(f"### {up.get('id', '')}: {up.get('title', '')}")
            lines.append(f"- **Subsystems:** {', '.join(up.get('subsystems', []))} ({fids})")
            lines.append(f"- **Problem:** {up.get('problem', '')}")
            lines.append(f"- **Target design:** {up.get('target_design', '')}")
            lines.append(f"- **Migration risk:** {up.get('migration_risk', '')}")
            lines.append("")
    else:
        lines.append("No cross-subsystem architectural upgrades flagged in this scope.")
        lines.append("")

    # Detailed verified findings
    lines.append("## 5. Detailed Verified Findings")
    lines.append("")
    lines.append("| ID | Sev | Category | Risk | Location | Mechanism & Proposed Fix |")
    lines.append("|---|---|---|---|---|---|")
    for f in findings_sorted:
        loc = f"`{f['file']}:{f['line_start']}-{f['line_end']}`"
        detail = f"{f['mechanism']} **Fix:** {f['proposed_fix']} *(verify: {f['verify_method']})*".replace("|", "\\|")
        lines.append(f"| `{f['id']}` | {f['severity']} | {f['category']} | {f['regression_risk']} | {loc} | {detail} |")
    lines.append("")

    # Ruled out
    lines.append("## 6. Ruled Out & Verified Clean")
    lines.append("")
    for ro in synthesis.get("ruled_out", []):
        lines.append(f"- **{ro.get('topic', '')}:** {ro.get('reason', '')} *(ref: {ro.get('reference', '')})*")
    for sl, clean_items in verified.get("verified_clean", {}).items():
        if clean_items:
            lines.append(f"- **`{sl}` verified clean:** " + "; ".join(clean_items))
    lines.append("")

    # Verified vs assumed
    lines.append("## 7. Verified vs. Assumed")
    lines.append("")
    lines.append(
        f"- **Verified deterministically:** `php -l` ({lint.get('checked_files', 0)} files), "
        f"`phpcs` ({phpcs.get('status')}), existing PHP invariant/gate tests, `D1`–`D5` file/line/quote verification "
        f"(deterministic gate passed={det_gate.get('passed')})."
    )
    lines.append(
        f"- **Verified by Independent Checker:** `{checker.get('verdict')}` after {checker.get('round')} round(s) "
        f"({checker.get('pass_summary', '')})."
    )
    unverifiable = [f["id"] for f in findings_sorted if f.get("verify_method") == "unverifiable_locally"]
    lines.append(
        f"- **Unverifiable locally / assumed:** "
        + (", ".join(f"`{x}`" for x in unverifiable) if unverifiable else "None in this scope; live WordPress/Polylang/DB runtime not executed.")
    )
    lines.append("")

    return "\n".join(lines)


def write_trace(
    trace_paths: list[Path],
    run_id: str,
    verified: dict[str, Any],
    det_gate: dict[str, Any],
    checker: dict[str, Any],
) -> dict[str, Any]:
    """Append one JSONL trace record for the weekly improvement loop."""
    total_findings = len(verified.get("findings", []))
    checker_rejections = len(checker.get("rejected_findings", []))
    denom = max(1, total_findings + checker_rejections)
    record = {
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "run_id": run_id,
        "slices": verified.get("slices", []),
        "total_findings": total_findings,
        "deterministic_failures": det_gate.get("failure_count", 0),
        "checker_rounds": checker.get("round", 0),
        "checker_verdict": checker.get("verdict", "UNKNOWN"),
        "checker_rejections": checker_rejections,
        "checker_rejection_rate": round(checker_rejections / denom, 4),
    }
    line = json.dumps(record) + "\n"
    for p in trace_paths:
        p.parent.mkdir(parents=True, exist_ok=True)
        with p.open("a", encoding="utf-8") as fh:
            fh.write(line)
    return record


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Render fralenuvole audit Markdown report and trace.")
    parser.add_argument("--run-dir", required=True, help="Run directory containing JSON artifacts.")
    parser.add_argument("--global-trace", default="", help="Optional global run_trace.jsonl path.")
    args = parser.parse_args(argv)

    run_dir = Path(args.run_dir).resolve()
    collect_data = json.loads((run_dir / "collect.json").read_text(encoding="utf-8"))
    verified = json.loads((run_dir / "verified_slices.json").read_text(encoding="utf-8"))
    synthesis = json.loads((run_dir / "synthesis.json").read_text(encoding="utf-8"))
    checker = json.loads((run_dir / "checker_verdict.json").read_text(encoding="utf-8"))
    det_gate = json.loads((run_dir / "deterministic_gate.json").read_text(encoding="utf-8"))
    esc_path = run_dir / "escalation.json"
    escalation = json.loads(esc_path.read_text(encoding="utf-8")) if esc_path.exists() else None

    md = render_report(collect_data, verified, synthesis, checker, det_gate, escalation)
    report_path = run_dir / "AUDIT-REPORT.md"
    report_path.write_text(md, encoding="utf-8")

    trace_paths = [run_dir / "run_trace.jsonl"]
    if args.global_trace:
        trace_paths.append(Path(args.global_trace).expanduser().resolve())
    trace = write_trace(trace_paths, run_dir.name, verified, det_gate, checker)

    print(json.dumps({"report": str(report_path), "trace": trace}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
