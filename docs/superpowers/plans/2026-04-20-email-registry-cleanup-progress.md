# Email Trigger Registry Cleanup — Progress Log

**Branch:** originally `feature/email-registry-cleanup`, shipped on `feature/workflow-ux-m1` (rebase never done; work merged forward). Phase 2 landed directly on `feature/workflow-ux-m1`.
**Started:** 2026-04-20
**Phase 1 shipped:** 2026-04-21 (v1.2.7, SHA `eb1c7c9`)
**Phase 2 shipped:** 2026-04-21 (v1.2.9, SHA `7b2ea94`)
**Owner:** Claude (with Mateo supervising)

This file is the single source of truth for session progress. Every meaningful change is logged here with a commit SHA and what it did. Optimised for reading after a gap (e.g., flight WiFi).

## Current status
### Phase 1 — registry cleanup (COMPLETE 2026-04-21)
- [x] Plan doc written (commit `c5f6e40`)
- [x] Audit: non-default `component_type_filter` usage on prod → **zero workflows. Safe to remove field.**
- [x] Registry schema: replace `hidden: bool` with `wiring_status: string` (commit `5e0d054`)
- [x] Promote wired-but-hidden categories (Classroom Visit, RP Session, Assessment, Schedule) (commit `5e0d054`)
- [x] Delete `schedule.account_activated` entry → moved to `get_legacy_trigger_aliases()` (commit `5e0d054`)
- [x] Add stub entries for 6 missing triggers (rows 11, 17-19, 20-21 of Chris's spreadsheet) (commit `5e0d054`)
- [x] Move legacy trigger-key reverse-mapping to separate `$legacy_trigger_aliases` (commit `5e0d054`)
- [x] Admin UI: render `wiring_status: stub` entries as disabled with tooltip
- [x] Remove `component_type_filter` visible field (commit `939cdac`)
- [x] Deploy guardrails: `bin/deploy.sh` with descendant check (commits `5389f68` + `ce1c31e`)
- [x] Deploy to test + prod (v1.2.7 @ SHA `eb1c7c9`, 2026-04-21 13:28 UTC)
- [x] Chris workflow seeder shipped (rows 4, 5, 6, 7, 8, 9, 10, 12, 13, 14, 15, 16, 22, 24) — `bin/seed-chris-workflows.php`

### Phase 2 — backend wiring of the 6 stubs (COMPLETE 2026-04-21)
- [x] Handler SQL fixes committed (`2b7c692`): §5.1 CV overdue per-component tighten + §5.3 column-reference bugs in `cron:action_plan_24h` / `cron:session_notes_24h` + timezone alignment (`current_time('mysql')` not `gmdate()`).
- [x] Test harness: `bin/test-email-phase2-stubs.php` — 29 assertions — all green on test + prod.
- [x] Admin-UI-equivalent smoke: `bin/test-email-phase2-registry-ui.php` — 40 assertions — all green on test + prod.
- [x] §5.1 flip: `classroom_visit.overdue` → wired (commit `7ca8760`).
- [x] §5.2 flip: 3 coaching session reminders → wired + 1h fuzz inline note (commit `fa1d38a`).
- [x] §5.3 flip: 2 post-session form reminders → wired, re-pointed at existing `cron:action_plan_24h` / `cron:session_notes_24h` keys + legacy alias regression fix (commit `621b1c8`).
- [x] Version bump 1.2.8 → 1.2.9 + STATUS + README (commit `2f2cc82`).
- [x] Deploy to test (SHA `9cc550e` → `7b2ea94` @ v1.2.9, 2026-04-21 18:43 UTC).
- [x] Deploy to prod (SHA `7b2ea94` @ v1.2.9, 2026-04-21 18:54 UTC).
- [x] Post-deploy verification on prod: 69/69 test assertions pass, `hl_core_schema_revision = 43` (parallel-session feature-tracker migration propagated cleanly), zero `[Phase2Test]` fixture residue.
- [ ] Launch Playwright agent on Chris's wired workflows — **deferred to Chris + Mateo review cycle**, not this session.

## Change log (newest first)

### 2026-04-21 — Phase 2 backend wiring shipped to prod (v1.2.9, SHA `7b2ea94`)

All 6 stubs flipped to `wired`. Scoped estimate in plan §5 was 4–6 dev-days; actual was ~0.5 day because the handoff spec underestimated how much was already done:

- **§5.1 `classroom_visit.overdue`** — The generic `cron:component_overdue` handler already supported arbitrary `component_type_filter`; `component_completion_subquery()` already had a `classroom_visit` case. Only missing piece was per-component scoping for the overdue path (ELCPB-Y2 multi-visit mentor pathways need CV #3 to fire when CV #1 is done). Added `$trigger_type` parameter to the completion subquery helper; overdue path now matches via `c.external_ref LIKE CONCAT('%"visit_number":', cv.visit_number, '%')` mirroring `HL_Classroom_Visit_Service::update_component_state()`. Reminder (upcoming) path stays cycle-scoped. One commit: `2b7c692` for the tighten, `7ca8760` for the flip.

- **§5.2 three coaching session reminders (5d / 24h / 1h)** — `cron:session_upcoming` was already fully implemented in Rev 39 generic triggers with configurable `trigger_offset_minutes`, scaled fuzz (5–30 min), `session_status='scheduled'` filter, hourly cron. Per-workflow dedup token already included `workflow_id` so the 3 different-offset workflows for the same session cannot collide — the handoff's claim that dedup needed to include offset was incorrect. Pure flag-flip, no handler change. 1h offset has an effective ~30–90 min send window due to hourly WP-Cron + 6 min fuzz; documented inline in the registry (don't bump fuzz clamp without reviewing dedup). Commit: `fa1d38a`.

- **§5.3 Action Plan + Coaching Notes Incomplete 24h After** — Three latent SQL bugs discovered in the pre-existing `cron:action_plan_24h` and `cron:session_notes_24h` handlers: `sub.submission_type` (column doesn't exist), `cs.mentor_user_id` (doesn't exist), `cs.enrollment_id` (doesn't exist). None had ever fired in prod because no active workflow used these keys. Rewrote both handlers: distinguish action-plan vs coach-notes by `role_in_session` (`supervisee` = mentor-authored, `supervisor` = coach-authored) per the canonical `HL_Coaching_Service::submit_form()` pattern. Mentor path joins `hl_enrollment` on `mentor_enrollment_id` for `user_id`. Coach path returns `enrollment_id = NULL` since coaches are staff users, not enrollment-scoped — verified end-to-end through the cron pipeline. Also fixed timezone bug: `session_datetime` is site-TZ; handlers now use `current_time('mysql')` instead of `gmdate()`. Added 30-day lookback clamp as perf insurance. Re-pointed the 2 stub registry entries at these (now-fixed) keys rather than building a new `cron:post_session_form_pending` key. Removed `cron:action_plan_24h` + `cron:session_notes_24h` from `get_legacy_trigger_aliases()` — they were misclassified as legacy during the Phase 1 refactor but are the canonical current keys. Commits: `2b7c692` (handler fixes), `621b1c8` (stub flip + legacy cleanup).

- **Test coverage** — `bin/test-email-phase2-stubs.php` (29 assertions: handler SQL via reflection + NULL-enrollment coach path end-to-end through `run_daily_checks()`) + `bin/test-email-phase2-registry-ui.php` (40 assertions: admin-UI-equivalent registry + save-payload validation — substitute for browser click-through when Playwright's MCP Chrome profile is locked by another process).

- **Schema note** — The Phase 2 deploy also carried the parallel-session's rev 43 migration (Feature Tracker's `status_updated_at` column, committed by a different session as `e18f9d5`). `hl_core_schema_revision` verified at 43 on prod post-deploy.

**Key facts saved to memory** (`project_email_phase2_2026_04.md`):
1. CV completion linkage is via `hl_component.external_ref` JSON + `hl_classroom_visit.visit_number`, not a direct FK.
2. Coaching submissions distinguish by `role_in_session` (`supervisee` / `supervisor`), not `submission_type`.
3. Coaches are direct WP staff users (`cs.coach_user_id`), NOT enrollment-scoped — handlers return `enrollment_id = NULL` for coach paths.
4. `session_datetime` is stored in site TZ; cron handlers comparing against it must use `current_time('mysql')`, not `gmdate()`.
5. Dedup token is `md5(trigger_key|workflow_id|user_id|entity_id|cycle_id)` — different workflow_ids always disambiguate, no need to include offset in the token.

---

### 2026-04-20 — CAUSE CONFIRMED: parallel Claude Code session deployed `feature/ticket-18-continuing-pathways` to prod

Mateo confirmed: a parallel Claude Code session was working on `feature/ticket-18-continuing-pathways` and deployed it to prod via SCP + tar-extract. The session didn't realize that branch was cut from `main` and therefore lacks all the `feature/workflow-ux-m1` work (M2 cascade, Course Surveys, Ticket QA, D-1 email, nav tickets, etc.). The other session has already self-diagnosed — quote: "I rolled back the D-1 email feature (commit 8c97c1a, marked 'deployed to prod' in b7a463b). Function send_ready_for_test_email is absent from prod."

**Division of labor going forward:**

- **Other session** owns prod recovery (they caused it, they're already assessing the damage). Expected: redeploy v1.2.6 from `feature/workflow-ux-m1`, verify schema, clean up `.playwright-mcp/`.
- **This session** owns the registry cleanup (plan + branch). Currently **paused** until prod is restored to v1.2.6, because the cleanup plan edits code that only exists in v1.2.6.

**Robustness items to fix later (identified, not executed):**

1. `.playwright-mcp/` is **not** in `.gitignore`. Both Claude sessions create artifacts there.
2. `.playwright-mcp/` is **not** in the tar `--exclude` list in `.github/workflows/deploy-test.yml` (line 15) or in `.claude/skills/deploy.md`'s documented tarball commands. Any future tarball ships Playwright artifacts.
3. The prod deploy pattern has no **branch-awareness check** — an SCP deploy from any local branch silently overwrites whatever was on prod. A pre-deploy sanity check (e.g., "prod version is X, tarball version is Y, is Y >= X?") would have caught this.

These need the user to approve; they're holdbacks for the next commit wave once recovery is confirmed.

**This branch stays paused** at commit `e330a99`. All work logged. Safe to resume once (a) prod back on v1.2.6, (b) user decides whether cleanup rebases onto `feature/workflow-ux-m1` or waits for main-merge.

---

### 2026-04-20 — SMOKING GUN: prod was overwritten with local-machine tarball (SUPERSEDED by above)

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

