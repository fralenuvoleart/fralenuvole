#!/usr/bin/env python3
"""Deterministic weekly trace summarizer for the fralenuvole audit improvement loop."""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any


def summarize_traces(trace_path: Path, days: int = 7) -> dict[str, Any]:
    """Summarize recent audit runs and checker rejection metrics without modifying anything."""
    if not trace_path.exists():
        return {"status": "empty", "runs": 0, "trace_path": str(trace_path)}

    cutoff = datetime.now(timezone.utc) - timedelta(days=days)
    recent: list[dict[str, Any]] = []
    for raw in trace_path.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw:
            continue
        try:
            rec = json.loads(raw)
        except json.JSONDecodeError:
            continue
        ts_str = rec.get("timestamp", "")
        try:
            ts = datetime.fromisoformat(ts_str.replace("Z", "+00:00"))
        except ValueError:
            continue
        if ts >= cutoff:
            recent.append(rec)

    if not recent:
        return {"status": "no_recent_runs", "days": days, "runs": 0, "trace_path": str(trace_path)}

    total_findings = sum(int(r.get("total_findings", 0)) for r in recent)
    total_det_fail = sum(int(r.get("deterministic_failures", 0)) for r in recent)
    total_chk_rej = sum(int(r.get("checker_rejections", 0)) for r in recent)
    verdicts = Counter(r.get("checker_verdict", "UNKNOWN") for r in recent)
    avg_rounds = round(sum(int(r.get("checker_rounds", 0)) for r in recent) / len(recent), 2)
    denom = max(1, total_findings + total_chk_rej)
    rejection_rate = round(total_chk_rej / denom, 4)

    return {
        "status": "ok",
        "window_days": days,
        "runs": len(recent),
        "total_findings": total_findings,
        "total_deterministic_failures": total_det_fail,
        "total_checker_rejections": total_chk_rej,
        "checker_rejection_rate": rejection_rate,
        "avg_checker_rounds": avg_rounds,
        "verdicts": dict(verdicts),
        "run_ids": [r.get("run_id") for r in recent],
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Summarize fralenuvole audit run traces.")
    parser.add_argument(
        "--trace",
        default="/home/francesco/Documents/Reports/fralenuvole/run_trace.jsonl",
        help="Path to global run_trace.jsonl.",
    )
    parser.add_argument("--days", type=int, default=7, help="Lookback window in days.")
    args = parser.parse_args(argv)
    summary = summarize_traces(Path(args.trace).expanduser().resolve(), days=args.days)
    print(json.dumps(summary, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
