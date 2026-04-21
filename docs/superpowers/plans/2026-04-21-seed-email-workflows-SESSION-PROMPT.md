# Session prompt: Seed Chris's email workflows via one-off `wp eval-file` script

> **Paste everything from the `---` line onward into a fresh Claude Code session as the first message.** Memory auto-load will pick up prior context. All remaining context is inlined.

---

You are continuing work on the HL Core WordPress plugin for Housman Learning Academy. Chris (the client) asked Mateo to build a set of email workflows for him. You're going to build them for him via a one-off PHP seeder script run via `wp eval-file`, not via the admin UI. Mateo specifically ruled out doing this through Playwright.

## Do this before you write any code

1. Read `CLAUDE.md` start-to-finish. Pay attention to Rule 0 — ALL deploys go through `bash bin/deploy.sh test|prod`.
2. Read `STATUS.md` — find the "Email Registry Cleanup" section to understand what's wired vs stubbed.
3. Read the registry cleanup plan + progress log (only the parts relevant to your work):
   - `docs/superpowers/plans/2026-04-20-email-registry-cleanup.md` — especially the "Chris's 19 workflows against the new registry" section and §9 (Open questions).
   - `docs/superpowers/plans/2026-04-20-email-registry-cleanup-progress.md` — context on why prod was rolled back and recovered; read if anything on prod seems missing.
4. Read `data/LMS Email Notification List - reorganized.xlsx`, sheet "Updated - LMS Master", rows 2–22 + 24. That's the source of truth for Chris's workflows. Use Python + openpyxl (`data_only=True`) — the pattern used in prior sessions:

   ```python
   import openpyxl
   wb = openpyxl.load_workbook("data/LMS Email Notification List - reorganized.xlsx", data_only=True)
   ws = wb["Updated - LMS Master"]
   for i, row in enumerate(ws.iter_rows(min_row=1, values_only=True), start=1):
       print(f"[{i}] {row[:11]}")
   ```

5. Read the email system code you'll be touching:
   - `includes/admin/class-hl-admin-emails.php` — look for `get_trigger_categories()` (registry), `get_valid_trigger_keys()` (validation whitelist), `validate_workflow_payload()` (security-boundary validator — you MUST call this), `get_condition_fields()` and `get_recipient_tokens()` (what's valid in conditions and recipients JSON).
   - `includes/services/class-hl-email-merge-tag-registry.php` — the canonical merge-tag syntax the builder uses. **Chris's spreadsheet uses `[user_first_name]` bracket-style placeholders; your seeder MUST translate those to the double-curly-brace system the registry expects (e.g., `{{recipient_first_name}}`).** Do not ship bracket placeholders — they will render as literal text in the email.
   - `includes/services/class-hl-email-block-renderer.php` — the block schema (type + per-type fields) templates are built from. Look at the existing migrated templates in `wp_hl_email_template` on test for real examples (query below).
   - `class-hl-email-recipient-resolver.php` — what recipient tokens resolve to.
6. Query existing templates on test for a real example of block JSON (don't guess the schema):

   ```bash
   ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 \
     'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress db query "SELECT template_id, name, subject, blocks FROM wp_hl_email_template LIMIT 3"'
   ```

7. **Report to Mateo before writing code:** your proposed block-JSON shape for one sample row, the merge-tag mapping table (his brackets → registry double-curlies), the trigger mapping per row, and your idempotency strategy. Wait for approval.

## Current state

- **Branch:** `feature/workflow-ux-m1` (the de-facto long-lived branch; `main` is behind).
- **Plugin version:** `1.2.8` (just bumped). Bump to `1.2.9` when the seeder script is committed, or leave if no functional code is changing on the plugin itself.
- **Schema revision:** 42.
- **Test + prod both at HEAD** of `feature/workflow-ux-m1`. Deploy manifests active on both.
- **Registry state:** Apr-21 cleanup landed. `wiring_status` enum is in. 6 stubs registered. Valid triggers come from `get_valid_trigger_keys()`.

## Workflows to build (12 clean + up to 2 pending decisions)

### In scope (12 — build these)

Each row below maps to a spreadsheet row and a trigger. Trigger keys MUST match what `get_trigger_categories()` declares; check each before hardcoding.

| Spreadsheet row | Workflow name (suggested) | Trigger (category → event) | `trigger_key` | Notes |
|---|---|---|---|---|
| 4 | New Pathway Enrollment (Non-Control-Group) | enrollment → pathway_assigned | `hl_pathway_assigned` | Recipient = user. Add condition to exclude control-group partnerships (ONLY if a `cycle.is_control_group` condition field exists; otherwise leave unconditional and let row 5 later add the control-group variant). |
| 6 | Course Completion with Certificate | course → completed | `hl_learndash_course_completed` | Recipient = user. Body references certificate download link. |
| 7 | Pathway Completion | enrollment → pathway_completed | `hl_pathway_completed` | Recipient = user. |
| 9 | Classroom Visit Window Opens (1 week before) | classroom_visit → reminder | `cron:component_upcoming` + `component_type_filter = classroom_visit` + `trigger_offset_minutes = 10080` (7d) | Recipient = Visitor (the coach/mentor who observes). CC = Teacher. Recipient tokens: verify the correct token IDs in `get_recipient_tokens()`. |
| 10 | Self-Reflection Prompt after Visit Submitted | classroom_visit → submitted | `hl_classroom_visit_submitted` | Recipient = teacher. |
| 12 | RP Session Window Opens (1 week before) | rp_session → reminder | `cron:component_upcoming` + `component_type_filter = reflective_practice_session` + `trigger_offset_minutes = 10080` (7d) | Recipient = mentor. CC = teacher. |
| 13 | RP Window Now Open | rp_session → reminder | `cron:component_upcoming` + `component_type_filter = reflective_practice_session` + `trigger_offset_minutes = 0` | Same trigger as row 12 with zero offset (fires on the day the window opens). Recipient = mentor. |
| 14 | Coaching Reminder: 1 Week Before (if not scheduled) | coaching → reminder | `cron:component_upcoming` + `component_type_filter = coaching_session_attendance` + `trigger_offset_minutes = 10080` | Recipient = mentor. Add condition `coaching.session_status in [not_scheduled]`. |
| 15 | Coaching Reminder: 2 Days Before Cycle Close (if not scheduled) | coaching → reminder | Same as row 14 with different offset | Recipient = mentor. Same condition. Offset = however far before cycle close you want — verify Chris's intent if ambiguous; 2 days = 2880. |
| 16 | Coaching Session Scheduled Confirmation | coaching → booked | `hl_coaching_session_created` | Recipient = mentor. CC = coach. |
| 22 | Coaching No-Show Follow-Up | coaching → missed | `hl_coaching_session_status_changed` + `statusFilter = missed` (stored as a condition on `session.new_status`) | Recipient = mentor. CC = director (verify director recipient token exists). |
| 24 | Low Engagement (14 days) | schedule → low_engagement | `cron:low_engagement_14d` | Recipient = user. CC = coach. |

### Pending decisions (DO NOT build without explicit answers from Mateo)

- **Row 5 — "Pathway Enrollment (Control Group)":** needs a condition field that identifies control-group cycles/partnerships. Before building, grep `get_condition_fields()` for anything like `cycle.is_control_group` or `partnership.type`. If nothing exists, STOP and ask Mateo whether to (a) add the condition field first, or (b) skip this workflow for now.
- **Row 8 — "Pre-Assessment Documentation":** spreadsheet says "upon activation of pathway enrollment." That could be `hl_pathway_assigned` with a condition on pathway type, or it could be a separate assessment-window-opens trigger. Ask Mateo which interpretation.

### Out of scope (do NOT build)

- Rows 2 and 3 — "User Registration #1A/#1B". Per Mateo's correction: WP `user_register` does not trigger emails in this system. Users only receive emails after enrollment. These rows are not real workflows.

### Stubbed (wait for Phase 2 backend)

Rows 11, 17, 18, 19, 20, 21 map to trigger events registered as `wiring_status: stub` in `get_trigger_categories()`. The save-handler whitelist excludes stub keys, so your seeder cannot create these workflows. Phase 2 (separate session, separate prompt) will build the backend wiring and flip them to `wired`. Skip silently.

## Implementation approach

Build a single PHP script at `bin/seed-chris-workflows.php`. Run via `wp eval-file` on test first, then prod. Commit to the repo so it deploys via `bin/deploy.sh` and can be re-run from any environment.

### Requirements (non-negotiable)

1. **Idempotent.** Use a stable `template_key` prefix like `chris_` (e.g., `chris_course_completion_v1`, `chris_pathway_assigned_v1`). On re-run: find by key → UPDATE; else INSERT. Same for workflows — identify by name prefix (e.g., `[Chris] Course Completion with Certificate`) for reliable re-runs.
2. **Use the validation layer.** Before each workflow INSERT, call `HL_Admin_Emails::validate_workflow_payload( $conditions, $recipients )`. If it returns `WP_Error`, log and skip — never bypass the security boundary. This is exactly the invariant that made the workflow builder safe; a seeder should not undermine it.
3. **Use the repository / `$wpdb->insert`, not direct SQL.** This gets you SQL-prepare safety, consistent timestamp fields, and compatibility with future schema tweaks. Audit-log each creation via `HL_Audit_Service::log( 'email_workflow_seeded', ...)`.
4. **All workflows ship as `status = 'draft'`.** Let Mateo review in the admin UI and hit "Activate" per workflow. Do not set `status = 'active'` automatically — that would send live emails the moment a qualifying event fires.
5. **Merge-tag translation.** Build a lookup table at the top of the script:

   ```php
   $merge_tag_map = array(
       '[user_first_name]'   => '{{recipient_first_name}}',
       '[user_last_name]'    => '{{recipient_last_name}}',
       '[assessment_name]'   => '{{assessment_name}}',
       '[school_district]'   => '{{school_district}}',
       '[pathway_name]'      => '{{pathway_name}}',
       // ... confirm every left-hand against Chris's spreadsheet, confirm every right-hand against HL_Email_Merge_Tag_Registry
   );
   ```
   Pre-flight check: for each translated body, grep for any remaining `[...]` bracket placeholder and fail loudly (`throw` or abort). A seed run must NOT ship a template with unresolved placeholders.

6. **Dry-run mode.** Support `--dry-run` (check `WP_CLI::has_config()` or a command-line arg). In dry-run, log every intended INSERT/UPDATE without writing to DB. First run on test should be dry-run to catch mapping errors before any DB writes.

### Recipient token verification

Chris's spreadsheet has recipient labels like "Visitor", "Mentor", "Teacher", "Coach", "Director". These must map to actual token keys in `HL_Email_Recipient_Resolver`. DO NOT guess — grep the resolver for registered tokens and build an explicit map. If any required token doesn't exist (e.g., "Director"), STOP and ask Mateo whether to (a) add the token, (b) substitute a similar one, or (c) skip that workflow's CC.

### Template structure (block JSON)

Inspect at least 3 existing templates on test (the `wp db query` in step 6 above). Expected shape is roughly:

```json
[
  {"type": "text", "content": "<p>Hello {{recipient_first_name}},</p>"},
  {"type": "text", "content": "<p>Body paragraph here.</p>"},
  {"type": "button", "label": "Click Me", "url": "{{some_url}}", "bg_color": "#2C7BE5", "text_color": "#FFFFFF"},
  {"type": "text", "content": "<p>Closing.</p>"}
]
```

Your seeder should output this shape. For Chris's `[BUTTON: Activate My Account]` markers: translate to an actual `button` block.

## Testing

1. **First pass: dry-run on test.** `ssh ... wp eval-file .../hl-core/bin/seed-chris-workflows.php --dry-run`. Check log output for:
   - No unresolved `[...]` placeholders.
   - Every workflow's `trigger_key` is in `get_valid_trigger_keys()`.
   - `validate_workflow_payload()` returns true for every workflow.
2. **Second pass: real run on test.** `wp eval-file ... (no flag)`. Verify:
   - 12 new rows in `wp_hl_email_workflow` named with `[Chris]` prefix, all `status = 'draft'`.
   - 12 new rows in `wp_hl_email_template` with `template_key` prefix `chris_`.
   - 12 audit log entries (action_type = `email_workflow_seeded`).
3. **Admin UI check on test.** Open Email Builder → Automated Workflows. All 12 should appear in the Drafts tab. Click into 2–3 randomly, confirm the cascade correctly reverse-maps to Category + Event (no "unrecognized trigger" warnings).
4. **Send-test via admin UI.** Use the built-in "Send Test" button on one workflow (Chris's test email = your own, never Chris's real email). Confirm the template renders with merge tags substituted. If anything looks wrong, fix the seeder and re-run.
5. **Re-run on test to verify idempotency.** Should see 0 new rows, 12 UPDATEs to existing rows (not 24 rows total). Critical — confirms re-runs won't duplicate.
6. **Only after test is green: deploy to prod + run on prod.** Requires Mateo's explicit approval for the prod run.

## Rules

### Deploy rules (HARD)

- Every deploy uses `bash bin/deploy.sh test|prod`. No raw tar/scp.
- Deploy to prod requires explicit approval from Mateo per session.
- The seed script itself doesn't deploy anywhere — it runs via `wp eval-file` on the target where it already lives (deployed via the script).

### Doc rules

- Update `STATUS.md` — under the Email Registry Cleanup section, add a sub-item like "Seeded 12 Chris workflows (draft state) via `bin/seed-chris-workflows.php`."
- Update `README.md` under "Email Registry Cleanup + Deploy Guardrails" — short bullet noting the seeder exists.
- Commit the seeder to the repo. Future edits (Chris wants to change subject lines, add workflows) happen by editing this script and re-running.

### What NOT to do

- **Don't set any workflow to `status = 'active'` automatically.** Mateo activates after review.
- **Don't bypass `validate_workflow_payload()`.** That's the whole point of going through the proper path.
- **Don't guess merge tags.** Build the map explicitly; fail loud on unresolved placeholders.
- **Don't build rows 2, 3, 5, 8 without explicit answers.** See the "Pending decisions" and "Out of scope" sections.
- **Don't touch the 6 stub workflows (rows 11, 17–21).** Those are Phase 2's job.
- **Don't edit `get_trigger_categories()`.** Your seeder creates workflow rows; it does not modify the registry.
- **Don't modify existing templates or workflows unowned by this seeder.** Scope everything to the `chris_` template_key prefix and `[Chris]` workflow name prefix.
- **Don't schedule the script to auto-run.** This is a one-off, run manually via `wp eval-file`.

## When you're done

Open the admin Email Builder on prod (after deploy + run), confirm all 12 workflows visible in Drafts tab. Ping Mateo to review. After his review, he decides per-workflow whether to activate.

Before claiming done:
- [ ] 12 workflows in `wp_hl_email_workflow` on prod, all `status = 'draft'`, all with `[Chris]` name prefix.
- [ ] 12 templates in `wp_hl_email_template` on prod with `chris_` key prefix.
- [ ] Seeder is idempotent — re-runs update, don't duplicate.
- [ ] STATUS.md + README.md updated.
- [ ] All merge-tag translations verified (no `[...]` brackets remaining in any stored template body).
- [ ] Send-test on at least 2 workflows confirmed rendered correctly.

## If you get stuck

- Merge tag Chris uses doesn't exist in the registry: stop and ask Mateo. Options: add to `HL_Email_Merge_Tag_Registry`, or substitute.
- Recipient token Chris needs doesn't exist: stop and ask.
- `validate_workflow_payload()` rejects a workflow: read the error. It's usually an invalid condition field, op, or recipient token. Fix the seeder, not the validator.
- Deploy script aborts: read the manifest on the target, investigate. Don't `--force` unless you understand why.
- Something on prod looks missing: read `project_rollback_incident_2026_04_20.md` in memory.

Memory files relevant to this task (auto-loaded at session start):
- `project_email_registry_cleanup_2026_04.md` — registry state, what Chris's 19 rows map to.
- `project_rollback_incident_2026_04_20.md` — context if prod state seems wrong.
- `project_deploy_guardrails_2026_04.md` — how deploys work now.
- `feedback_always_use_deploy_script.md` — deploy rule.

Acknowledge you've read this prompt, CLAUDE.md, STATUS.md, and the registry cleanup plan before starting. Report your proposed merge-tag map, recipient token map, and one sample workflow's full payload (subject + block JSON + conditions + recipients) for Mateo's review. Wait for go-ahead before running the seeder.
