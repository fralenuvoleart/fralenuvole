#!/usr/bin/env python3
"""Deterministic collector for the fralenuvole PHP plugin audit workflow."""

from __future__ import annotations

import argparse
import concurrent.futures
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

EXCLUDED_DIRS = {"vendor", "node_modules", ".dev", ".git", "plans", "tools"}
EXCLUDED_PREFIXES = ("assets/lib/", "tests/")
TOP_SLICES = ("core", "includes", "admin", "public", "modules", "root")

FUNC_RE = re.compile(
    r"^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\("
)
PHPCS_IGNORE_RE = re.compile(r"phpcs:(?:ignore|disable)")
DEBUG_OPTION_KEY_RE = re.compile(r"['\"]debug_[a-z0-9_]+['\"]\s*=>")


def discover_php_files(repo_root: Path) -> list[str]:
    """Return sorted relative paths of shipped PHP files."""
    files: list[str] = []
    for dirpath, dirnames, filenames in os.walk(repo_root):
        dirnames[:] = sorted(d for d in dirnames if d not in EXCLUDED_DIRS)
        rel_dir = Path(dirpath).relative_to(repo_root)
        for name in sorted(filenames):
            if not name.endswith(".php"):
                continue
            rel = (rel_dir / name).as_posix() if str(rel_dir) != "." else name
            if any(rel.startswith(p) for p in EXCLUDED_PREFIXES):
                continue
            files.append(rel)
    return sorted(files)


def assign_slices(files: list[str], requested_slices: list[str] | None) -> dict[str, list[str]]:
    """Group PHP files into requested path prefixes or default top-level subsystems."""
    if requested_slices:
        result: dict[str, list[str]] = {s: [] for s in requested_slices}
        for rel in files:
            for s in requested_slices:
                prefix = s.rstrip("/") + "/"
                if rel == s or rel.startswith(prefix):
                    result[s].append(rel)
                    break
        return {k: v for k, v in result.items() if v}

    grouped: dict[str, list[str]] = {s: [] for s in TOP_SLICES}
    for rel in files:
        top = rel.split("/", 1)[0] if "/" in rel else "root"
        if top in grouped:
            grouped[top].append(rel)
        else:
            grouped["root"].append(rel)
    return {k: v for k, v in grouped.items() if v}


def run_php_lint(repo_root: Path, files: list[str]) -> dict[str, Any]:
    """Run php -l concurrently across all shipped PHP files."""
    errors: list[dict[str, str]] = []

    def _lint_one(rel: str) -> dict[str, str] | None:
        proc = subprocess.run(
            ["php", "-l", str(repo_root / rel)],
            capture_output=True,
            text=True,
            check=False,
        )
        if proc.returncode != 0:
            return {"file": rel, "output": (proc.stdout + proc.stderr).strip()}
        return None

    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        for res in pool.map(_lint_one, files):
            if res is not None:
                errors.append(res)

    return {
        "checked_files": len(files),
        "error_count": len(errors),
        "passed": len(errors) == 0,
        "errors": errors,
    }


def run_phpcs(repo_root: Path) -> dict[str, Any]:
    """Run vendor/bin/phpcs --report=json using repo phpcs.xml."""
    phpcs_bin = repo_root / "vendor" / "bin" / "phpcs"
    if not phpcs_bin.exists():
        return {"status": "unavailable", "reason": "vendor/bin/phpcs not found"}

    proc = subprocess.run(
        [str(phpcs_bin), "-q", "--report=json"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    try:
        payload = json.loads(proc.stdout)
    except json.JSONDecodeError:
        return {
            "status": "error",
            "returncode": proc.returncode,
            "stderr": proc.stderr.strip()[:500],
        }

    totals = payload.get("totals", {})
    by_file: dict[str, list[dict[str, Any]]] = {}
    root_prefix = str(repo_root.resolve()) + "/"
    for abs_path, info in payload.get("files", {}).items():
        msgs = info.get("messages", [])
        if not msgs:
            continue
        rel = abs_path[len(root_prefix):] if abs_path.startswith(root_prefix) else abs_path
        by_file[rel] = [
            {
                "line": m.get("line"),
                "type": m.get("type"),
                "source": m.get("source"),
                "message": m.get("message"),
            }
            for m in msgs
        ]

    return {
        "status": "ok",
        "errors": totals.get("errors", 0),
        "warnings": totals.get("warnings", 0),
        "fixable": totals.get("fixable", 0),
        "files_with_issues": len(by_file),
        "issues_by_file": by_file,
    }


def run_phpstan(repo_root: Path) -> dict[str, Any]:
    """Run vendor/bin/phpstan only if binary and config exist."""
    bin_path = repo_root / "vendor" / "bin" / "phpstan"
    config = next(
        (repo_root / name for name in ("phpstan.neon", "phpstan.neon.dist") if (repo_root / name).exists()),
        None,
    )
    if not bin_path.exists():
        return {"status": "unavailable", "reason": "vendor/bin/phpstan not found"}
    if config is None:
        return {"status": "unconfigured", "reason": "no phpstan.neon or phpstan.neon.dist in repo"}

    proc = subprocess.run(
        [str(bin_path), "analyse", "--error-format=json", "--no-progress"],
        cwd=repo_root,
        capture_output=True,
        text=True,
        check=False,
    )
    try:
        data = json.loads(proc.stdout)
    except json.JSONDecodeError:
        return {"status": "error", "returncode": proc.returncode, "stderr": proc.stderr.strip()[:500]}
    return {"status": "ok", "totals": data.get("totals", {}), "files": data.get("files", {})}


def run_existing_tests(repo_root: Path) -> dict[str, Any]:
    """Run existing dependency-free PHP invariant and gate tests."""
    results: dict[str, Any] = {}
    commands = {
        "invariants": ["php", "tests/invariants/run.php", "--quiet"],
        "phone_pattern_gate": ["php", "tests/phone-pattern-gate-test.php"],
    }
    for name, cmd in commands.items():
        script_path = repo_root / cmd[1]
        if not script_path.exists():
            results[name] = {"status": "missing"}
            continue
        proc = subprocess.run(cmd, cwd=repo_root, capture_output=True, text=True, check=False)
        results[name] = {
            "status": "passed" if proc.returncode == 0 else "failed",
            "returncode": proc.returncode,
            "summary": (proc.stdout.strip().splitlines()[-3:] if proc.stdout.strip() else []),
            "stderr": proc.stderr.strip()[:300],
        }
    return results


def analyze_php_file(repo_root: Path, rel: str) -> dict[str, Any]:
    """Compute deterministic LOC, long-function, nesting depth, and phpcs:ignore metrics."""
    lines = (repo_root / rel).read_text(encoding="utf-8", errors="replace").splitlines()
    non_blank = sum(1 for ln in lines if ln.strip())
    phpcs_ignores = [i + 1 for i, ln in enumerate(lines) if PHPCS_IGNORE_RE.search(ln)]

    hot_functions: list[dict[str, Any]] = []
    i = 0
    n = len(lines)
    while i < n:
        m = FUNC_RE.match(lines[i])
        if not m:
            i += 1
            continue
        fn_name = m.group(1)
        start_line = i + 1
        depth = 0
        max_depth = 0
        opened = False
        j = i
        while j < n:
            ln = lines[j]
            opens = ln.count("{")
            closes = ln.count("}")
            if opens:
                opened = True
            depth += opens - closes
            if depth > max_depth:
                max_depth = depth
            if opened and depth <= 0:
                break
            j += 1
        end_line = min(j + 1, n)
        fn_loc = end_line - start_line + 1
        if fn_loc > 80 or max_depth > 4:
            hot_functions.append(
                {
                    "name": fn_name,
                    "line_start": start_line,
                    "line_end": end_line,
                    "loc": fn_loc,
                    "max_depth": max_depth,
                }
            )
        i = max(j + 1, i + 1)

    return {
        "file": rel,
        "loc": len(lines),
        "non_blank_loc": non_blank,
        "phpcs_ignore_count": len(phpcs_ignores),
        "phpcs_ignore_lines": phpcs_ignores,
        "hot_functions": hot_functions,
    }


def check_system_patterns(repo_root: Path, files: list[str]) -> list[dict[str, Any]]:
    """Run mechanical invariant checks derived from systemPatterns.md and FACTS.md."""
    checks: list[dict[str, Any]] = []

    # SP1: No option keys starting with debug_ in config-options*.php
    sp1_hits: list[dict[str, Any]] = []
    for rel in files:
        if "config-options" not in Path(rel).name:
            continue
        lines = (repo_root / rel).read_text(encoding="utf-8", errors="replace").splitlines()
        for idx, ln in enumerate(lines, 1):
            if DEBUG_OPTION_KEY_RE.search(ln):
                sp1_hits.append({"file": rel, "line": idx, "snippet": ln.strip()[:120]})
    checks.append(
        {
            "id": "SP1_debug_option_prefix",
            "rule": "Option keys must not start with debug_ (hidden when WP_DEBUG is off)",
            "passed": len(sp1_hits) == 0,
            "hits": sp1_hits,
        }
    )

    # SP2: No blog-scoped cache keys in core/cache/
    sp2_hits: list[dict[str, Any]] = []
    for rel in files:
        if not rel.startswith("core/cache/"):
            continue
        lines = (repo_root / rel).read_text(encoding="utf-8", errors="replace").splitlines()
        for idx, ln in enumerate(lines, 1):
            stripped = ln.strip()
            if stripped.startswith(("//", "*", "/*")):
                continue
            if "get_current_blog_id(" in ln or "switch_blog" in ln:
                sp2_hits.append({"file": rel, "line": idx, "snippet": stripped[:120]})
    checks.append(
        {
            "id": "SP2_no_blog_scoped_cache_keys",
            "rule": "Cache keys are single-site by design (no get_current_blog_id / switch_blog)",
            "passed": len(sp2_hits) == 0,
            "hits": sp2_hits,
        }
    )

    # SP3: frl_cache_remember() never calls get_option/get_site_option
    sp3_hits: list[dict[str, Any]] = []
    cache_helpers = repo_root / "core/cache/functions-cache.php"
    if cache_helpers.exists():
        lines = cache_helpers.read_text(encoding="utf-8", errors="replace").splitlines()
        in_fn = False
        depth = 0
        for idx, ln in enumerate(lines, 1):
            if not in_fn and "function frl_cache_remember(" in ln:
                in_fn = True
            if in_fn:
                depth += ln.count("{") - ln.count("}")
                stripped = ln.strip()
                if not stripped.startswith(("//", "*", "/*")):
                    if "get_option(" in ln or "get_site_option(" in ln:
                        sp3_hits.append({"file": "core/cache/functions-cache.php", "line": idx, "snippet": stripped[:120]})
                if depth <= 0 and "{" in "".join(lines[max(0, idx - 3):idx]):
                    break
    checks.append(
        {
            "id": "SP3_remember_no_get_option",
            "rule": "frl_cache_remember() must never call get_option()/get_site_option()",
            "passed": len(sp3_hits) == 0,
            "hits": sp3_hits,
        }
    )

    # SP4: FRL_RUM_COLUMNS contract check
    rum_receiver = repo_root / "core/debugger/rum/rum-receiver.php"
    rum_cols = None
    if rum_receiver.exists():
        text = rum_receiver.read_text(encoding="utf-8", errors="replace")
        m = re.search(r"FRL_RUM_COLUMNS\s*=\s*array\((.*?)\);", text, re.DOTALL)
        if m:
            rum_cols = len(re.findall(r"['\"][a-z0-9_]+['\"]", m.group(1)))
    checks.append(
        {
            "id": "SP4_rum_columns_contract",
            "rule": "FRL_RUM_COLUMNS count recorded as single source of truth for rum.py COLS",
            "passed": rum_cols is not None and rum_cols > 0,
            "column_count": rum_cols,
        }
    )

    return checks


def collect(repo_root: Path, out_dir: Path, requested_slices: list[str] | None = None) -> dict[str, Any]:
    """Run all deterministic checks and write collect.json + slice_inputs/*.json."""
    out_dir.mkdir(parents=True, exist_ok=True)
    slice_dir = out_dir / "slice_inputs"
    slice_dir.mkdir(parents=True, exist_ok=True)

    files = discover_php_files(repo_root)
    slices = assign_slices(files, requested_slices)

    lint = run_php_lint(repo_root, files)
    phpcs = run_phpcs(repo_root)
    phpstan = run_phpstan(repo_root)
    tests = run_existing_tests(repo_root)
    file_metrics = [analyze_php_file(repo_root, rel) for rel in files]
    metrics_by_file = {m["file"]: m for m in file_metrics}
    pattern_checks = check_system_patterns(repo_root, files)

    total_loc = sum(m["loc"] for m in file_metrics)
    hot_fn_count = sum(len(m["hot_functions"]) for m in file_metrics)
    phpcs_ignore_total = sum(m["phpcs_ignore_count"] for m in file_metrics)

    payload: dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "repo_root": str(repo_root),
        "shipped_php_files": len(files),
        "total_loc": total_loc,
        "hot_function_count": hot_fn_count,
        "phpcs_ignore_total": phpcs_ignore_total,
        "php_lint": lint,
        "phpcs": phpcs,
        "phpstan": phpstan,
        "tests": tests,
        "system_pattern_checks": pattern_checks,
        "slices": {name: len(flist) for name, flist in slices.items()},
        "file_metrics": file_metrics,
    }

    (out_dir / "collect.json").write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")

    phpcs_by_file = phpcs.get("issues_by_file", {}) if phpcs.get("status") == "ok" else {}
    for slice_name, slice_files in slices.items():
        slug = slice_name.replace("/", "__")
        slice_payload = {
            "slice": slice_name,
            "slug": slug,
            "file_count": len(slice_files),
            "total_loc": sum(metrics_by_file[f]["loc"] for f in slice_files),
            "files": [
                {
                    **metrics_by_file[f],
                    "phpcs_issues": phpcs_by_file.get(f, []),
                }
                for f in slice_files
            ],
        }
        (slice_dir / f"{slug}.json").write_text(json.dumps(slice_payload, indent=2) + "\n", encoding="utf-8")

    return payload


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Deterministic collector for fralenuvole audit.")
    parser.add_argument("--repo", default=".", help="Path to fralenuvole repository root.")
    parser.add_argument("--out", required=True, help="Output run directory.")
    parser.add_argument(
        "--slices",
        default="",
        help="Comma-separated subsystem path prefixes (e.g. core/cache,core/debugger). Empty = all top-level.",
    )
    args = parser.parse_args(argv)
    requested = [s.strip() for s in args.slices.split(",") if s.strip()] or None
    payload = collect(Path(args.repo).resolve(), Path(args.out).resolve(), requested)
    print(
        json.dumps(
            {
                "shipped_php_files": payload["shipped_php_files"],
                "total_loc": payload["total_loc"],
                "php_lint_passed": payload["php_lint"]["passed"],
                "phpcs_errors": payload["phpcs"].get("errors"),
                "phpcs_warnings": payload["phpcs"].get("warnings"),
                "phpstan_status": payload["phpstan"].get("status"),
                "tests": {k: v.get("status") for k, v in payload["tests"].items()},
                "slices": payload["slices"],
            }
        )
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
