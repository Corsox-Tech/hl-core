# Email Trigger Registry Cleanup — Progress Log

**Branch:** `feature/email-registry-cleanup`
**Started:** 2026-04-20
**Owner:** Claude (with Mateo supervising)

This file is the single source of truth for session progress. Every meaningful change is logged here with a commit SHA and what it did. Optimised for reading after a gap (e.g., flight WiFi).

## Current status
- [x] Plan doc written (commit `c5f6e40`)
- [x] Audit: non-default `component_type_filter` usage on prod → **zero workflows. Safe to remove field.**
- [ ] Registry schema: replace `hidden: bool` with `wiring_status: string`
- [ ] Promote wired-but-hidden categories (Classroom Visit, RP Session, Schedule/Low Engagement)
- [ ] Delete `schedule.account_activated` entry
- [ ] Add stub entries for 6 missing triggers (rows 11, 17-19, 20-21 of Chris's spreadsheet)
- [ ] Move legacy trigger-key reverse-mapping to separate `$legacy_trigger_aliases`
- [ ] Admin UI: render `wiring_status: stub` entries as disabled with tooltip
- [ ] Remove `component_type_filter` visible field OR move behind Advanced disclosure (decide from audit)
- [ ] Deploy to test + verify
- [ ] Deploy to prod + verify
- [ ] Launch Playwright agent on the 13 wired workflows for Chris

## Change log (newest first)

### 2026-04-20 — SMOKING GUN: prod was overwritten with local-machine tarball (not a deliberate rollback)

Investigation after Mateo said "I had no idea it was rolled back." The rollback was caused by **a tarball built from Mateo's local checkout being deployed to prod**, not an intentional version revert. The Playwright MCP artifacts that ended up on prod prove it.

**Evidence:**

1. **Prod `hl-core.php` mtime = `2026-04-20 14:45:32 UTC`.** Local `hl-core.php` mtime = `2026-04-20 10:45:32 EDT = 14:45:32 UTC`. **Identical.** Tar preserves file mtimes — this is the tarball source.

2. **Prod `.playwright-mcp/` contains identical files to local `.playwright-mcp/`.** Console logs `console-2026-04-20T14-02-10-368Z.log` through `console-2026-04-20T14-09-05-630Z.log` all match byte-for-byte.

3. **Prod `/tmp/hl-core.tar.gz` mtime = `2026-04-20 15:11:41 UTC`.** Local `/tmp/hl-core.tar.gz` mtime = `2026-04-20 11:09 EDT = 15:09 UTC`. **2-minute gap** — consistent with `scp` + `ssh tar -xzf` sequence.

4. **Both tarballs contain v1.2.2 code** (verified with `tar -xzOf ... hl-core.php | grep HL_CORE_VERSION`).

5. **Prod has NO `.git` directory.** Auto-pull from GitHub is not the mechanism.

6. **`.github/workflows/deploy-test.yml` exists** but it targets the **TEST** server (44.221.6.201), not prod. Only fires on push to `main`. Not the culprit.

7. **Prod `.bash_history` has no tarball-extract entry for today** — but the SSH user's history file only captures interactive shells; a one-line `ssh host "tar -xzf ..."` command from a remote client wouldn't write to the remote's history.

**What was on prod before (lost):** Everything on `feature/workflow-ux-m1` that wasn't on `main`:
- M2 cascade picker + `get_trigger_categories()`
- Course Survey Builder (3 tables, schema rev 42)
- Ticket QA workflow (`ready_for_test` / `test_failed` statuses)
- D-1 email notification on `ready_for_test`
- Ticket #6 Support sidebar restoration
- Ticket #23 Community sidebar split
- Multiple email/survey fixes
- Version 1.2.6 (rolled back to 1.2.2)

**Potential source of the rogue deploy:**
- Another Claude Code session running in parallel on Mateo's machine
- A script / IDE task / sync tool auto-deploying
- A manual action Mateo doesn't recall

The deploy definitely originated from Mateo's local machine (byte-identical tarball proof) — but *who/what issued the commands* is unclear. Not diagnosing further without Mateo's input.

**Open risks:**
- Schema/code drift: if prod's DB has tables from rev 29-42 (Course Surveys, Ticket QA, etc.) but the code is v1.2.2 (rev 27ish), queries will fail.
- `.playwright-mcp/` artifacts are now on prod, visible to anyone scraping the plugin directory.
- No `.playwright-mcp/` in `.gitignore` or tar `--exclude` list → future tarballs will re-ship them.

**Halting all deploys/changes until Mateo confirms:**
- What will prevent a repeat deploy from rolling back any recovery work?
- Is it safe to redeploy v1.2.6 from `feature/workflow-ux-m1`, or has something on that branch been compromised too?

---

### 2026-04-20 — CRITICAL FINDING: prod was rolled back today, plan target is ambiguous (SUPERSEDED by above)

Mateo suggested checking whether M2 might be deployed but uncommitted. Thorough check revealed something bigger:

| Environment | Version | `class-hl-admin-emails.php` lines | M2 cascade? | File mtime |
|---|---|---|---|---|
| **Prod** | 1.2.2 | 1896 | **No** | 2026-04-20 14:45 UTC |
| **Test** | 1.2.6 | 2876 | **Yes** | 2026-04-20 14:41 UTC |
| `feature/workflow-ux-m1` HEAD | 1.2.6 | (matches test) | Yes | — |
| `main` / `feature/ticket-18-continuing-pathways` / `feature/email-registry-cleanup` | 1.2.2 | 1896 | **No** | — |

**What this means:**

1. **Prod was deployed today at 14:45 UTC with v1.2.2 code.** This is a rollback from v1.2.6 that was on prod previously (during the D-1 email work). Reason unknown.
2. **Test has the M2 cascade** from `feature/workflow-ux-m1` v1.2.6. That deploy happened 4 minutes before the prod rollback.
3. **Prod's email builder does NOT have `get_trigger_categories()` at all.** No Category→Event cascade. No `hidden: true` flag. It has the older flat-trigger-key dropdown builder (from before M2).

**Consequences for Chris's 19 workflows:**

- If we launch the Playwright agent **against prod**, it will encounter the pre-M2 builder. The registry cleanup plan doesn't apply. Chris would use the flat dropdown.
- If we launch it **against test**, the cascade is available, but workflows created there won't be on prod — Chris can't actually use them until prod gets re-upgraded.
- The cleanup plan only makes sense if we're targeting a codebase that has the cascade.

**The right question to answer before proceeding:**

1. Why was prod rolled back to v1.2.2 at 14:45 UTC today? Bug, deliberate revert, something else?
2. What's the plan to get prod back on the latest code (v1.2.6 or equivalent)?
3. Until prod upgrades, do we build Chris's workflows on test, or on prod using the old builder?

The branching-strategy question I raised earlier is secondary. The deployment-state question is primary.

---

### 2026-04-20 — BLOCKER: branching strategy decision needed (SUPERSEDED by above)

Discovered while preparing to edit `get_trigger_categories()`: that function does not exist on the current branch (`feature/email-registry-cleanup`, cut from `feature/ticket-18-continuing-pathways`, which was cut from `main`).

`get_trigger_categories()` and the M2 cascade picker were introduced on `feature/workflow-ux-m1` in commits `3c53332` (Apr 16) and `5255e06` (Apr 16). That branch has **30+ commits ahead of main** covering M2 cascade, Course Survey Builder, Ticket QA workflow, Ticket D-1 email notification, several nav/sidebar tickets, etc. None of it has merged.

Options for Mateo to decide:

1. **Rebase cleanup onto `feature/workflow-ux-m1`** — fastest, but this cleanup can't merge until workflow-ux-m1 merges. If workflow-ux-m1 doesn't merge, this doesn't either.
2. **Wait for workflow-ux-m1 to merge to main**, then rebase cleanup onto main. Clean but blocked on external action.
3. **Cherry-pick just the M2 cascade commits** onto current cleanup branch — isolates dependency, but weird history.
4. **Merge workflow-ux-m1 → main first** as a separate action, then cut cleanup from main.

Not my call to make. Logging and waiting for user direction.

---

### 2026-04-20 — Audit: component_type_filter usage

**Prod:** `SELECT COUNT(*) FROM wp_hl_email_workflow` → **0 rows.** Clean slate.
**Test:** 4 rows exist, all with `component_type_filter = NULL`. Two are in `deleted` status and reference the legacy `user_register` trigger_key — confirms the `$legacy_trigger_aliases` design is needed for clean reverse-mapping even though nothing production-facing uses it.

**Decision:** Remove the `component_type_filter` visible form field entirely. JS auto-sets the hidden input from the chosen Event's `componentType`. No Advanced disclosure needed since there is no legitimate use case for a non-default override in the existing data.

**Column stays in `hl_email_workflow` table** — backend still uses it. The change is purely UI: the field becomes non-editable, set only by cascade JS.

---

### 2026-04-20 — Plan doc + progress log

Commit `c5f6e40`. Plan at `2026-04-20-email-registry-cleanup.md`. Progress log (this file) created alongside.

