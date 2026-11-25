#!/usr/bin/env python3
"""Deterministic verifier (hard gates D1-D5) for fralenuvole audit slice findings."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

VALID_CATEGORIES = {
    "bug",
    "error",
    "kiss",
    "modularity",
    "maintainability",
    "performance",
    "architecture",
}
VALID_SEVERITIES = {"critical", "high", "medium", "low"}
VALID_RISKS = {"safe", "caution", "high"}
VALID_VERIFY = {"static", "grep", "executed", "unverifiable_locally"}

REQUIRED_FINDING_FIELDS = (
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
)

# Documented phpcs.xml exclusions that must never be reported as coding-standard findings.
EXCLUDED_RULE_PATTERNS = (
    re.compile(r"\byoda\s+condition", re.IGNORECASE),
    re.compile(r"\bshort\s+ternary\b", re.IGNORECASE),
    re.compile(r"\btext[- ]domain\s+constant\b", re.IGNORECASE),
    re.compile(r"\bmissing\s+translators?\s+comment", re.IGNORECASE),
    re.compile(r"\binvalid\s+class\s+file\s+name\b", re.IGNORECASE),
    re.compile(r"\bnot\s+hyphenated\s+lowercase\b", re.IGNORECASE),
)


def _norm_ws(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def verify_slice_findings(
    repo_root: Path,
    collect_data: dict[str, Any],
    slice_outputs: list[dict[str, Any]],
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Validate all slice findings against D1-D5 and deduplicate across slices."""
    failures: list[dict[str, Any]] = []
    accepted: list[dict[str, Any]] = []
    verified_clean: dict[str, list[str]] = {}
    seen_keys: set[tuple[str, int, str]] = set()
    file_cache: dict[str, list[str]] = {}

    phpcs_by_file: dict[str, list[dict[str, Any]]] = (
        collect_data.get("phpcs", {}).get("issues_by_file", {})
        if collect_data.get("phpcs", {}).get("status") == "ok"
        else {}
    )

    for slice_obj in slice_outputs:
        if not isinstance(slice_obj, dict) or "slice" not in slice_obj or not isinstance(slice_obj.get("findings"), list):
            failures.append(
                {
                    "check_id": "D1",
                    "slice": str(slice_obj.get("slice", "unknown")) if isinstance(slice_obj, dict) else "unknown",
                    "finding_id": None,
                    "reason": "Slice output missing required keys ('slice', 'findings' list).",
                }
            )
            continue

        slice_name = str(slice_obj["slice"])
        verified_clean[slice_name] = [str(x) for x in slice_obj.get("verified_clean", [])]

        for idx, item in enumerate(slice_obj["findings"]):
            if not isinstance(item, dict):
                failures.append({"check_id": "D1", "slice": slice_name, "finding_id": f"idx:{idx}", "reason": "Finding is not an object."})
                continue

            fid = str(item.get("id") or f"{slice_name}#{idx}")
            finding_failed = False

            # D1: Schema & enum check
            missing = [k for k in REQUIRED_FINDING_FIELDS if k not in item]
            if missing:
                failures.append({"check_id": "D1", "slice": slice_name, "finding_id": fid, "reason": f"Missing fields: {missing}"})
                continue

            if item["category"] not in VALID_CATEGORIES:
                failures.append({"check_id": "D1", "slice": slice_name, "finding_id": fid, "reason": f"Invalid category: {item['category']}"})
                finding_failed = True
            if item["severity"] not in VALID_SEVERITIES:
                failures.append({"check_id": "D1", "slice": slice_name, "finding_id": fid, "reason": f"Invalid severity: {item['severity']}"})
                finding_failed = True
            if item["regression_risk"] not in VALID_RISKS:
                failures.append({"check_id": "D1", "slice": slice_name, "finding_id": fid, "reason": f"Invalid regression_risk: {item['regression_risk']}"})
                finding_failed = True
            if item["verify_method"] not in VALID_VERIFY:
                failures.append({"check_id": "D1", "slice": slice_name, "finding_id": fid, "reason": f"Invalid verify_method: {item['verify_method']}"})
                finding_failed = True

            # D2: File, line range, and verbatim evidence_quote check
            rel_file = str(item["file"]).lstrip("./")
            try:
                line_start = int(item["line_start"])
                line_end = int(item["line_end"])
            except (TypeError, ValueError):
                failures.append({"check_id": "D2", "slice": slice_name, "finding_id": fid, "reason": "line_start/line_end must be integers."})
                continue

            abs_file = (repo_root / rel_file).resolve()
            if not str(abs_file).startswith(str(repo_root.resolve())) or not abs_file.is_file():
                failures.append({"check_id": "D2", "slice": slice_name, "finding_id": fid, "reason": f"File not found: {rel_file}"})
                continue

            if rel_file not in file_cache:
                file_cache[rel_file] = abs_file.read_text(encoding="utf-8", errors="replace").splitlines()
            lines = file_cache[rel_file]

            if line_start < 1 or line_end < line_start or line_end > len(lines):
                failures.append(
                    {
                        "check_id": "D2",
                        "slice": slice_name,
                        "finding_id": fid,
                        "reason": f"Line range {line_start}-{line_end} out of bounds for {rel_file} ({len(lines)} lines).",
                    }
                )
                finding_failed = True
            else:
                quote = _norm_ws(str(item["evidence_quote"]))
                window_start = max(0, line_start - 2)
                window_end = min(len(lines), line_end + 1)
                window_text = _norm_ws("\n".join(lines[window_start:window_end]))
                if len(quote) < 5 or quote not in window_text:
                    failures.append(
                        {
                            "check_id": "D2",
                            "slice": slice_name,
                            "finding_id": fid,
                            "reason": f"evidence_quote not found in {rel_file}:{line_start}-{line_end}.",
                        }
                    )
                    finding_failed = True

            # D3: No duplicate of existing phpcs finding at the same file:line
            phpcs_lines = {m.get("line") for m in phpcs_by_file.get(rel_file, [])}
            if any(ln in phpcs_lines for ln in range(line_start, line_end + 1)) and item["category"] == "maintainability":
                failures.append(
                    {
                        "check_id": "D3",
                        "slice": slice_name,
                        "finding_id": fid,
                        "reason": f"Duplicates phpcs issue already reported at {rel_file}:{line_start}-{line_end}.",
                    }
                )
                finding_failed = True

            # D4: No finding recommending documented phpcs.xml exclusions
            combined_text = f"{item['mechanism']} {item['proposed_fix']}"
            if any(pat.search(combined_text) for pat in EXCLUDED_RULE_PATTERNS):
                failures.append(
                    {
                        "check_id": "D4",
                        "slice": slice_name,
                        "finding_id": fid,
                        "reason": "Finding conflicts with a documented phpcs.xml deliberate exclusion.",
                    }
                )
                finding_failed = True

            # D5: Concrete trigger/mechanism & fix required for medium+ findings
            if item["severity"] in {"critical", "high", "medium"}:
                if len(str(item["mechanism"]).strip()) < 15 or len(str(item["proposed_fix"]).strip()) < 10:
                    failures.append(
                        {
                            "check_id": "D5",
                            "slice": slice_name,
                            "finding_id": fid,
                            "reason": "Medium+ finding must specify a concrete mechanism (>=15 chars) and proposed_fix (>=10 chars).",
                        }
                    )
                    finding_failed = True

            if not finding_failed:
                dedup_key = (rel_file, line_start, str(item["category"]))
                if dedup_key not in seen_keys:
                    seen_keys.add(dedup_key)
                    accepted.append({**item, "file": rel_file, "line_start": line_start, "line_end": line_end, "slice": slice_name})

    gate = {
        "passed": len(failures) == 0,
        "total_accepted": len(accepted),
        "failure_count": len(failures),
        "failures": failures,
    }
    verified = {
        "slices": list(verified_clean.keys()),
        "verified_clean": verified_clean,
        "findings": accepted,
    }
    return gate, verified


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Deterministic verifier for fralenuvole audit findings.")
    parser.add_argument("--repo", default=".", help="Repository root.")
    parser.add_argument("--collect", required=True, help="Path to collect.json.")
    parser.add_argument("--slices-dir", required=True, help="Directory with slice finding JSON files.")
    parser.add_argument("--out-dir", required=True, help="Run output directory.")
    args = parser.parse_args(argv)

    repo_root = Path(args.repo).resolve()
    collect_data = json.loads(Path(args.collect).read_text(encoding="utf-8"))
    slice_files = sorted(Path(args.slices_dir).glob("*.json"))
    slice_outputs = [json.loads(p.read_text(encoding="utf-8")) for p in slice_files]

    gate, verified = verify_slice_findings(repo_root, collect_data, slice_outputs)
    out_dir = Path(args.out_dir).resolve()
    (out_dir / "deterministic_gate.json").write_text(json.dumps(gate, indent=2) + "\n", encoding="utf-8")
    (out_dir / "verified_slices.json").write_text(json.dumps(verified, indent=2) + "\n", encoding="utf-8")

    print(json.dumps({"passed": gate["passed"], "accepted": gate["total_accepted"], "failures": gate["failure_count"]}))
    return 0 if gate["passed"] else 1


if __name__ == "__main__":
    sys.exit(main())
