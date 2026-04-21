# Session prompt: Extend email-workflow seeder with 6 newly-wired rows (11, 17–21)

> **Paste everything from the `---` line onward into a fresh Claude Code session as the first message.** Memory auto-load will pick up prior context. All remaining context is inlined.

---

You are continuing work on the HL Core WordPress plugin for Housman Learning Academy. A prior session created a PHP seeder (`bin/seed-email-workflows.php`) that inserted 14 email workflow drafts on prod from a client spreadsheet. Six rows were skipped because the triggers they needed were registered as stubs at the time. Another parallel session has since wired those triggers to real backend handlers (Phase 2, 2026-04-21). Your job is to extend the existing seeder with the 6 now-buildable rows, dry-run, then deploy + run on prod.

This is a small extension — not a new script. Idempotency + validation + security-boundary patterns are already in place; you add entries to the existing data structure.

## Do this before writing any code

1. Read `CLAUDE.md` (full). Rule 0 — deploys go through `bash bin/deploy.sh test|prod`.
2. Read `STATUS.md` — "Email Registry Cleanup" section — to understand what's already wired vs what Phase 2 just flipped.
3. Read the **existing seeder** end-to-end: `bin/seed-email-workflows.php`. Note:
   - Its naming/prefix convention for `template_key` (e.g., what prefix does it use? The memory hint says workflows are stored with user-agnostic names, no client-specific prefix).
   - How idempotency is implemented (SELECT by key/name → UPDATE else INSERT).
   - How it calls `HL_Admin_Emails::validate_workflow_payload()`.
   - How recipient tokens + conditions JSON are shaped.
   - How merge tags are translated from the spreadsheet's `[bracket]` syntax to the registry's `{{double_curly}}` syntax.
   - Where the 14 workflow definitions live in the file (an array, a function, etc.).
4. Read the spreadsheet for rows 11, 17, 18, 19, 20, 21 (sheet "Updated - LMS Master"):
   ```python
   import openpyxl
   wb = openpyxl.load_workbook("data/LMS Email Notification List - reorganized.xlsx", data_only=True)
   ws = wb["Updated - LMS Master"]
   for r in [11, 17, 18, 19, 20, 21]:
       row = list(ws.iter_rows(min_row=r, max_row=r, values_only=True))[0]
       print(f"--- Row {r} ---")
       for header, val in zip(['Type','Topic','Step','Trigger','When','Program','Description','Recipient','Sender','CC','Copy'], row):
           print(f"  {header}: {val}")
   ```
5. Read the Phase 2 memory entry: `project_email_phase2_2026_04.md` (auto-loaded). Understand the 3 handler groups that now power these 6 rows, and the 1h fuzz caveat for row 19.
6. Read `includes/admin/class-hl-admin-emails.php` → `get_trigger_categories()` to confirm the 6 events are now `wiring_status: wired` (not stub). If any are still stub, STOP — Phase 2 wasn't fully applied to this environment and this seeder extension will fail validation.
7. Grep `HL_Email_Merge_Tag_Registry` for generic component-based merge tags (`{{component_name}}`, `{{component_title}}`, `{{component_window_start}}`, `{{component_due_date}}`, or similar). Build your translation map. If the tags don't exist, fall back to literal text (same pattern the 14-row seeder uses for unmapped tags).

**Report to Mateo before coding:** your proposed 6 new seeder entries (full payload — name, trigger_key, conditions, recipients, template subject + block JSON) + confirmation that the 6 trigger keys are wired in the current registry. Wait for approval.

## Current state (at session start)

- Branch: `feature/workflow-ux-m1` (long-lived; `main` is behind).
- Plugin version: 1.2.9 on prod + test. Do NOT bump for this seeder extension — adding data rows via tooling is not a runtime-code change. Version bumps are for plugin code changes.
- Schema revision: 43.
- Prod has 14 drafts from the earlier seeder run. After your extension + re-run, prod should have 20 drafts.
- Deploy manifests active on both environments. Descendant check guardrail will enforce your branch is a successor.

## The 6 rows to add

Each row below maps to a spreadsheet row and a trigger. Trigger keys must match what `get_trigger_categories()` declares after Phase 2. Verify each before hardcoding.

| Spreadsheet row | Suggested workflow name | Trigger (category → event) | `trigger_key` + extras | Notes |
|---|---|---|---|---|
| **11** | Classroom Visit Overdue (1 day after window closes) | classroom_visit → overdue | `cron:component_overdue` + `component_type_filter = classroom_visit` + offset 0 (or whatever offset the Phase 2 handler expects — verify in the handler; fuzz window handles the "1 day after" semantics) | Recipient = `triggering_user` (cron naturally fires for both visitor and teacher enrollments; no separate CC). Body: "your classroom visit form is overdue" generic framing per the 14-row seeder's style. |
| **17** | Coaching Reminder — 5 Days Before Scheduled Session | coaching → reminder_5d_before_session | `cron:session_upcoming` + `trigger_offset_minutes = 7200` (5 days) | Recipient = `triggering_user` (the mentor whose enrollment owns the session). Single-recipient workflow. |
| **18** | Coaching Reminder — 24 Hours Before Scheduled Session | coaching → reminder_24h_before_session | `cron:session_upcoming` + `trigger_offset_minutes = 1440` (24 hours) | Same recipient + shape as row 17. |
| **19** | Coaching Reminder — 1 Hour Before Scheduled Session | coaching → reminder_1h_before_session | `cron:session_upcoming` + `trigger_offset_minutes = 60` (1 hour) | Same recipient + shape. **Important:** include in the template body copy an explicit acknowledgement of the fuzz window, e.g., "(your session is coming up in about an hour)" — NOT "exactly in one hour" — because the hourly cron means this reminder fires between roughly 30-90 minutes before the session. Don't promise precision the backend can't deliver. |
| **20** | Action Plan Incomplete — 24h After Session | coaching → action_plan_incomplete_24h_after | `cron:action_plan_24h` (no offset field — handler is hardcoded to 24h lookback) | Recipient = `triggering_user` (mentor — action plan is `supervisee`-role submission). CC = `assigned_coach` (verified recipient token for this trigger context per Phase 2 work). |
| **21** | Coaching Notes Incomplete — 24h After Session | coaching → notes_incomplete_24h_after | `cron:session_notes_24h` (same lookback shape as row 20) | Recipient = `triggering_user` (coach — notes are `supervisor`-role submission). NULL enrollment_id is expected on this path (coach is staff, not enrolled); the Phase 2 test harness confirmed NULL propagates cleanly through the pipeline. No CC — coach is writing the notes, no one else needs the reminder. |

## Merge tags for the 6 rows

Chris's spreadsheet uses bracket placeholders. The 14-row seeder established a translation pattern — follow it. Specific tokens these 6 rows likely need:

- `[user_first_name]` → `{{recipient_first_name}}` (already mapped by the 14-row seeder)
- `[coaching_session_name]` → check for `{{component_name}}` or `{{component_title}}`; fall back to literal "your coaching session"
- `[classroom_visit_name]` → same pattern; fall back to literal "your classroom visit"
- `[session_date]` → `{{session_date}}` (if registry renders formatted; automation service handles timezone formatting at send time)
- `[session_time]` → if `{{session_date}}` is date-only in the registry, keep separate `[session_time]` as literal fallback; if date+time, drop the separate tag
- `[Coach Email]` / `[Coach email]` → `{{coach_email}}` (registry tag; confirm it renders for the trigger contexts you're using, especially for row 20 CC path)

**Pre-flight check** (same as the 14-row seeder's): after translation, any `[...]` bracket placeholder remaining in a stored body is a fatal error — abort the seed rather than ship an unresolved placeholder.

## Implementation

1. **Add 6 entries to the existing seeder's workflow-definitions array** in `bin/seed-email-workflows.php`. Match the existing shape exactly — don't invent new fields.
2. **Template-key naming:** follow the existing prefix/convention. If the 14-row seeder uses `chris_*` keys, extend with `chris_classroom_visit_overdue_v1`, `chris_coaching_reminder_5d_v1`, etc. If it uses a different prefix (memory suggests the names may have been made user-agnostic), match that.
3. **Workflow names:** match the existing prefix convention (bracketed prefix or none). Stay consistent with the 14 already on prod.
4. **Conditions for row 11:** none required — classroom visit overdue is cycle-scoped already via the cron handler's per-component tightening (Phase 2 §5.1). Don't add `cycle.is_control_group` filter; control-group cycles legitimately have classroom visits too.
5. **Conditions for rows 17/18/19:** none. The `cron:session_upcoming` handler already filters `session_status = 'scheduled'`.
6. **Conditions for rows 20/21:** none. The handlers filter `session_status = 'attended' AND session_datetime < now - 24h AND submission IS NULL` internally.
7. **Status on all 6: `'draft'`.** Same rule as the 14 — Mateo activates manually after review.
8. **validate_workflow_payload() on all 6** before insert. Don't bypass. If it rejects, the error message identifies the invalid field — fix the payload, not the validator.

## Testing

1. **Dry-run on test:**
   ```bash
   ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
     'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && \
      wp --path=/opt/bitnami/wordpress eval-file wp-content/plugins/hl-core/bin/seed-email-workflows.php --dry-run'
   ```
   Expected output: 20 workflow definitions considered. 14 UPDATE (existing), 6 INSERT (new). 0 skipped. 0 validation errors. 0 unresolved placeholders.
2. **Real run on test:** same command without `--dry-run`. Expected counts: 14 UPDATE + 6 INSERT on workflows, same on templates, 20 audit rows (seeded action).
3. **Verify on test:**
   - `wp db query "SELECT COUNT(*) FROM wp_hl_email_workflow WHERE status='draft'"` → 20.
   - Spot-check one of the 6 new rows by opening test admin UI (your IP is whitelisted per security hardening) — confirm cascade reverse-maps to the correct Category → Event, no "unrecognized trigger" warning.
4. **Idempotency re-run on test:** expect 20 UPDATE + 0 INSERT. Confirms re-runs don't duplicate.
5. **Deploy to prod** via `bash bin/deploy.sh prod` — ships the updated seeder script. Requires Mateo's explicit approval per session.
6. **Dry-run on prod** first, same as test step 1. Confirm 14 UPDATE + 6 INSERT planned.
7. **Ping Mateo with dry-run output** before the real prod run.
8. **Real run on prod** after Mateo acks.
9. **Verify on prod:** 20 drafts total, 6 new audit events, empty email queue (drafts don't fire), no unexpected sends.

## Rules

### Deploy rules (HARD)

- Every deploy goes through `bash bin/deploy.sh test|prod`. No raw tar/scp.
- Deploy to prod requires explicit Mateo approval per session.
- If the descendant check aborts: read the target's `.deploy-manifest.json`, investigate, don't `--force`.

### Doc rules

- Update `STATUS.md` — under the existing "Email Registry Cleanup" section or the sub-item where the 14-row seed was logged, add a short line: "Extended seeder with 6 Phase-2-dependent rows (11, 17-21). 20 drafts total on prod." Brief, one line.
- README.md likely doesn't need an update for this extension — the seeder was already documented. If you want to bump the "14 drafts" mention to "20 drafts" for accuracy, do it. Minor.

### What NOT to do

- **Don't bump `HL_CORE_VERSION`.** Data-only change; runtime code is unchanged.
- **Don't set any workflow to `status = 'active'`.** Mateo activates.
- **Don't modify the existing 14 seeder entries** unless Mateo explicitly asks. They're already in prod and any edit would UPDATE the stored row on next run.
- **Don't bypass `validate_workflow_payload()`.**
- **Don't touch the registry (`get_trigger_categories()`).** Phase 2 already flipped all 6 stubs to wired. If any are not wired in the env you're working against, stop — that's an environment-state bug.
- **Don't edit the v1 admin form** (the rollback escape hatch in `render_workflow_form()`).

## When you're done

Before claiming done:
- [ ] 20 workflow rows in `wp_hl_email_workflow` on prod, all `status = 'draft'`.
- [ ] 20 template rows in `wp_hl_email_template` with the project's key-prefix convention.
- [ ] Seeder re-run on prod produces 20 UPDATE + 0 INSERT (idempotency confirmed).
- [ ] Every stored body has zero `[...]` bracket placeholders remaining.
- [ ] STATUS.md updated.
- [ ] Dry-run output shared with Mateo before the real prod run.

Ping Mateo after each milestone (proposed payloads, test-run counts, prod-dry-run counts, real-prod-run counts). Small extensions deserve small check-ins.

## If you get stuck

- Merge tag doesn't exist in registry: stop and ask Mateo. Options: add to registry (backend work, out of scope), or fall back to literal.
- Recipient token resolves empty in a test: check `HL_Email_Recipient_Resolver` for that token's context requirements. If the handler isn't populating the expected field in `build_hook_context()`, that's a backend bug, not a seeder bug — report it.
- Deploy script aborts: read the manifest on the target before `--force`.

Relevant memory files (auto-loaded at session start):
- `project_email_registry_cleanup_2026_04.md` — what got refactored, what the 14-row seeder created.
- `project_email_phase2_2026_04.md` — Phase 2 backend wiring details, the 6 triggers you're now targeting.
- `project_rollback_incident_2026_04_20.md` — context on why the branching + deploy guardrails exist.
- `project_deploy_guardrails_2026_04.md` — how `bin/deploy.sh` works.
- `feedback_always_use_deploy_script.md` — the deploy rule.

Acknowledge you've read this prompt, CLAUDE.md, the existing seeder, and the 6 spreadsheet rows before you start. Report your proposed 6 payloads (names + trigger configs + sample template for one row). Wait for Mateo's go-ahead before running anything.
