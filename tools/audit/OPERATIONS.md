# Fralenuvole Audit Workflow — Operations & Schedule (Disabled by Default)

## 1. On-Demand Execution
Run inside Antigravity (`run_workflow`):
- **Full plugin (all 6 top-level slices: `core`, `includes`, `admin`, `public`, `modules`, `root`):**
  - `ScriptPath`: `/home/francesco/code/pbservices/wp-content/plugins/fralenuvole/tools/audit/workflow.py`
  - `Args`: `{"slices": "all"}`
- **Targeted folders before editing:**
  - `Args`: `{"slices": "modules/wsform,public/schema"}`

Outputs are archived under `~/Documents/Reports/fralenuvole/runs/<YYYYMMDD-HHMMSS>/AUDIT-REPORT.md` (previous runs are never overwritten).

## 2. Scheduled Automation Config (DISABLED — Enable Only After Owner Approval)
Use `/automation` in Antigravity with `CRON_TZ=Asia/Manila` when ready to enable:

```json
{
  "enabled": false,
  "timezone": "Asia/Manila",
  "jobs": [
    {
      "name": "fralenuvole-weekly-audit",
      "cron": "0 9 * * 1",
      "workspace": "/home/francesco/code/pbservices/wp-content/plugins/fralenuvole",
      "instruction": "Run tools/audit/workflow.py via run_workflow with Args={\"slices\": \"all\"}. Do not edit any PHP files. Summarize the verdict, top 5 actions, and link the generated AUDIT-REPORT.md."
    },
    {
      "name": "fralenuvole-audit-weekly-improvement",
      "cron": "30 9 * * 1",
      "workspace": "/home/francesco/code/pbservices/wp-content/plugins/fralenuvole",
      "instruction": "Run `python3 tools/audit/weekly_review.py --days 7` and inspect any checker_verdict.json / deterministic_gate.json failures from the last 7 days. Using checker_rejection_rate as the primary outcome metric, identify the single weakest link and propose ONE highest-leverage fix (gate, script, routing, or prompt — not prompts only). Change nothing until Francesco approves."
    }
  ]
}
```

## 3. Triggering Fixes From an Audit Report
- **Small batch (1–5 findings):** In chat, reference the report path and exact finding IDs / action numbers. Fixes follow `advise -> confirm -> approve -> apply` and run `php -l`, `vendor/bin/phpcs -q`, `php tests/invariants/run.php --quiet`, and `php tests/phone-pattern-gate-test.php`.
- **Large cross-subsystem batch:** Invoke `/swarm` (`workspace="share"`) with the report path and approved finding IDs so workers edit separate subsystems in parallel and pass all PHP gates before merging.
