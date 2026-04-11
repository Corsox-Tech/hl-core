# Email System v2 — Session Handoff

## Status

Planning complete. Execution not yet started. Both reviewers gave 0/10 error-likelihood on the design.

- Spec: frozen and reviewed (5-phase review, 86 items addressed in Appendix A)
- Plans: 3 track plans written (Track 1 admin UX, Track 2 builder, Track 3 backend)
- Total tasks across all tracks: 52
- No code has been written yet
- No branches have been cut yet

Confidence is high. The design has been stress-tested by two preliminary reviewers and three senior expert reviewers (frontend, PHP, backend), then cross-debated. Both final reviewers closed at 0/10 design error-likelihood.

---

## How to start (copy this prompt into new session)

Paste the block below into a fresh Claude Code session to kick off execution:

```
I'm continuing the Email System v2 implementation for HL Core. You have ZERO memory of the planning conversation — everything you need is in the handoff doc and the plans.

Before doing ANYTHING:

1. Read CLAUDE.md (project rules — especially the mandatory workflow rules).
2. Read STATUS.md and report where the build queue stands. Per project rule #2, do NOT code until I confirm.
3. Read docs/superpowers/plans/2026-04-11-email-v2-handoff.md (this handoff doc) in full.
4. Read docs/superpowers/specs/2026-04-10-email-system-v2-design.md including Appendix A (sections A.1 through A.7.15). A.7.15 contains schema corrections that OVERRIDE earlier appendix items where they conflict.
5. Read the three track plans in this order:
   a. docs/superpowers/plans/2026-04-11-email-v2-track3-backend.md  (START HERE — foundation)
   b. docs/superpowers/plans/2026-04-11-email-v2-track1-admin-ux.md
   c. docs/superpowers/plans/2026-04-11-email-v2-track2-builder.md

Then use the superpowers:subagent-driven-development skill to execute. Dispatch one fresh subagent per task and review between tasks so the orchestrator context stays clean across all 52 tasks.

Build order is NOT negotiable: Track 3 Tasks 1, 2, 5, and 23 must land FIRST (HL_Roles, evaluator routing, audit service with get_last_event, assigned_mentor resolver). After those four tasks are merged, Track 1 and remaining Track 3 tasks can run in parallel with Track 2. Track 2 is fully independent and can start immediately if you want to parallelize.

After every task: update STATUS.md build queue checkboxes and README.md "What's Implemented" per CLAUDE.md rule #3. A task is NOT done until both files are updated.

Start by reading the files above, reporting STATUS.md state, and asking me to confirm the starting task.
```

---

## Files to read first (in order)

1. `CLAUDE.md` — project rules (mandatory workflow, protected files, terminology, code conventions)
2. `STATUS.md` — build queue context; check what is `[x]` done, `[~]` in-progress, `[ ]` pending
3. `docs/superpowers/specs/2026-04-10-email-system-v2-design.md` — full spec + Appendix A (A.1–A.7.15)
4. `docs/superpowers/plans/2026-04-11-email-v2-track3-backend.md` — **START HERE (foundation)**
5. `docs/superpowers/plans/2026-04-11-email-v2-track1-admin-ux.md`
6. `docs/superpowers/plans/2026-04-11-email-v2-track2-builder.md`

Also reference as needed:
- `.claude/skills/deploy.md` — SSH / WP-CLI / scp commands for test + prod
- `.claude/skills/architecture.md` — domain architecture and doc file index
- `README.md` — current "What's Implemented" section and file tree

---

## Build order

**Hard prerequisites — these four Track 3 tasks MUST land before anything else:**

1. Track 3 Task 1 — `HL_Roles` helper class (parses `hl_enrollment.roles` stored as either JSON or legacy CSV)
2. Track 3 Task 2 — evaluator routing layer
3. Track 3 Task 5 — `HL_Audit_Service` with the new `get_last_event()` method (Track 1's force-resend task depends on this)
4. Track 3 Task 23 — `assigned_mentor` resolver

**After those four are merged:**

- Track 1 (admin UX) can start
- Remaining Track 3 tasks can run in parallel with Track 1
- Track 2 (builder) is fully independent and can start at ANY time — even immediately, before the four prerequisites, if parallelizing

**Suggested execution flow:**

1. Land the 4 Track 3 prerequisites sequentially (review between each)
2. Fan out: Track 1 + remaining Track 3 in parallel, Track 2 in parallel from the start
3. Integration testing after all 52 tasks are checked off
4. Deploy to test server, manual browser smoke
5. Deploy to prod

---

## Execution approach

Use the **superpowers:subagent-driven-development** skill.

- Dispatch one fresh subagent per task
- Review the subagent's output between tasks
- Keep the orchestrator context clean across all 52 tasks
- Do NOT let a single subagent handle multiple tasks — each task gets its own fresh context
- After each task: update STATUS.md + README.md, commit, move to next

This matters because 52 tasks is far too many for a single orchestrator context. Subagent dispatch keeps each task focused and prevents context rot.

---

## Key context the new session needs

- **Schema corrections**: Spec Appendix A.7.15 contains schema corrections that are authoritative. Where earlier appendix items (A.1–A.7.14) conflict with A.7.15 or with the track plans, the **plans and A.7.15 win**.
- **Test harness**: This project has NO PHPUnit setup. Tests are WP-CLI scripts invoked via `wp eval-file bin/test-email-v2-track*.php`. Do NOT try to set up PHPUnit.
- **Deploy**: See `.claude/skills/deploy.md` for SSH commands (test server = AWS Lightsail, prod = Hostinger via scp tarball). Neither environment auto-pulls from git; both require manual deploy.
- **CLAUDE.md rule #3**: Update STATUS.md + README.md after EVERY task. A task is not done until both files are updated. Self-check: "Did I update BOTH files?"
- **Enrollment roles format**: The repo has `hl_enrollment.roles` stored as **JSON** (not CSV). `HL_Roles::parse_stored()` (Track 3 Task 1) handles both formats — always use the helper, never parse raw.
- **No cohort table**: Per Grand Rename V3, `hl_cohort` does not exist. Hierarchy is Partnership → Cycle → Pathway. See CLAUDE.md terminology section.
- **Tar excludes**: When building deploy tarballs, use `--exclude='./vendor'` (with the `./`), NOT `--exclude='vendor'` — bare form also strips `assets/js/vendor/`.

---

## Testing per track

- **Track 1 (admin UX)**: `wp eval-file bin/test-email-v2-track1.php` + manual browser smoke on admin pages
- **Track 2 (builder)**: `wp eval-file bin/test-email-v2-track2.php` + manual browser smoke on the builder UI
- **Track 3 (backend)**: `wp eval-file bin/test-email-v2-track3.php` + `wp hl-core smoke-test`

Use Playwright MCP for browser smoke. Per user feedback (see memory `feedback_test_before_claiming.md`): do NOT claim a frontend fix works based on code reading alone — verify in a real browser.

---

## What "done" looks like

- [ ] All 52 tasks checked off in the three plan files
- [ ] All three CLI test suites pass
- [ ] `wp hl-core smoke-test` passes
- [ ] `STATUS.md` Email System v2 section shows complete
- [ ] `README.md` "What's Implemented" updated with Email System v2 entry
- [ ] Deployed to test server
- [ ] Manual browser smoke passes (admin pages + builder)
- [ ] Committed and pushed to `main`

Do NOT mark the feature complete until every item above is checked.

---

## Known gotchas

These are the landmines the review surfaced. Each was addressed in the plans, but the new session must understand WHY the plans look the way they do:

- **Sortable.js is not jQuery UI**. Sortable.js is a standalone library. Its `wp_enqueue_script` dependencies must be `['jquery']` only, NOT `['jquery-ui-sortable']`. Loading jQuery UI Sortable will break drag-and-drop.
- **Nested Sortables need unique group names**. Each nested Sortable instance needs a distinct `group` name per (parentIndex, side). Reusing a group name across nested instances causes items to jump between containers.
- **Pill input Enter key**. When the user hits Enter in a pill `<input>`, you MUST call `e.preventDefault()` or the surrounding form will submit and navigate away.
- **`CURDATE()` is server timezone**. Do NOT use `CURDATE()` in SQL. Use `current_time('Y-m-d')` throughout — the site runs on a non-UTC server timezone and this has bitten us before (see commit `9437770`).
- **Draft cleanup query**. Draft cleanup must query `$wpdb->options` with `esc_like()` on the option name prefix, NOT a raw `LIKE` on an unescaped string. Raw LIKE is a SQL injection and performance footgun.
- **Preview iframe sandbox**. The preview iframe needs `sandbox="allow-same-origin allow-popups"` — deliberately NO `allow-scripts`. Email previews must never execute JS.
- **Unicode subjects**. Subject lines with non-ASCII characters MUST be passed through `mb_encode_mimeheader()` before `wp_mail()`, or they render as mojibake in most clients.
- **`HL_Audit_Service::get_last_event()` ordering**. This method is added by **Track 3 Task 5**. Track 1's force-resend task **depends on it**. If you try to execute Track 1's force-resend task before Track 3 Task 5 lands, it will fail. This is why Task 5 is in the hard prerequisite set.

---

## Review history

For future Claude if it needs to understand why the design looks this way:

- **5-phase review process** was used:
  1. Two preliminary reviewers
  2. Three senior expert reviewers (frontend, PHP, backend)
  3. Cross-debate between the senior reviewers
  4. Integration of feedback into spec
  5. Final sign-off
- **86 review items** were addressed and folded into spec Appendix A, sections A.1 through A.7
- **Schema corrections** are consolidated in Appendix A.7.15 and **override** earlier A.x items where they conflict
- **Both final reviewers** closed at **0/10 design error-likelihood** — the highest confidence we've had on any HL Core design to date

If the new session encounters something in the plans that looks wrong, the first instinct should be to re-read the relevant Appendix A section — the decision is almost certainly documented there with its rationale. Do NOT undo review decisions without checking the appendix first.

---

*Handoff written 2026-04-11. Planning session closed with zero open questions.*
