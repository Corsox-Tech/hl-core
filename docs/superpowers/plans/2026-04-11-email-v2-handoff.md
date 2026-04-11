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
Continue Email System v2 execution.

First read these in order:
1. CLAUDE.md (project rules)
2. STATUS.md (build queue)
3. docs/superpowers/plans/2026-04-11-email-v2-handoff.md (full context + build order + execution strategy + quality gate)
4. docs/superpowers/specs/2026-04-10-email-system-v2-design.md (spec + Appendix A)

Then execute the plans using the HYBRID strategy documented in the handoff doc:
- Track 3: pure subagent-driven (one fresh subagent per task)
- Track 1: grouped subagents (related tasks dispatched together)
- Track 2: inline execution via superpowers:executing-plans

For EVERY task or task group, you MUST run the per-task quality gate documented in the handoff doc:
- Phase A: Implementation
- Phase B: 2 parallel review agents with different expertise
- Phase C: Cross-review debate until consensus
- Phase D: 2 senior expert agents (Sr SWE + WP expert) score and debate
- Phase E: Feedback back to implementer with pushback support
- Phase F: Ask "What would strict senior engineers say?" — fix anything flagged
- Phase G: Ask "Likelihood of user errors 0-10?" — iterate until 0
- Phase H: Commit and update .claude/v2-build-journal.md

Start with Track 3 Task 1 (HL_Roles helper). Maintain .claude/v2-build-journal.md from task 1 onward. Every subagent dispatch must include the full build journal + git diff --name-only output.

The memory system has 3 relevant entries under `project_email_v2_2026_04.md` and two `reference_*` files for schema realities (hl_enrollment.roles is JSON not CSV, HL_Audit_Service::get_last_event signature).

Per CLAUDE.md rule #3: after every task, update STATUS.md build queue and README.md "What's Implemented" section.
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

## Execution Strategy — Hybrid (not pure subagent-driven)

**Why hybrid:** Pure subagent-driven development has a real weakness when tasks share emergent patterns, helpers, or APIs. Each subagent starts blind. For 52 tasks across 3 interconnected tracks, this causes drift. Use the following per-track strategy:

| Track | Strategy | Rationale |
|-------|----------|-----------|
| **Track 3 (32 tasks, backend)** | **Pure subagent-driven** — one fresh subagent per task | Tasks are mostly independent (DB migrations, isolated SQL queries, helper classes). Plan is tight. |
| **Track 1 (15 tasks, admin UX)** | **Grouped subagents** — dispatch related tasks together (e.g., all 3 row-action tasks in one call, condition builder shell + JS together) | UI convention continuity matters. Emergent patterns like "use `.hl-email-admin` wrapper" must be consistent across all admin pages. |
| **Track 2 (5 tasks, builder)** | **Inline execution** (via superpowers:executing-plans) | Only 2 files (`email-builder.js`, `class-hl-email-block-renderer.php`). Context continuity is more valuable than isolation. |

**Mandatory orchestrator discipline regardless of strategy:**

1. **Maintain a build journal** at `.claude/v2-build-journal.md`. After each task completes, append 2-3 lines:
   - What was added (file:function names)
   - What patterns were established (naming conventions, helper usage)
   - What helpers now exist and their signatures
   - Any deviations from the plan and why

2. **Every subagent dispatch must include:**
   - The target task from the plan
   - The current build journal (in full — it's compact by design)
   - `git diff --name-only` output showing files touched since the last Track boundary
   - For Track 1 subagents: a note listing committed Track 3 helpers and their signatures

3. **Integration checkpoints** after each Track foundation lands:
   - After Track 3 Tasks 1, 2, 5, 23: run `wp eval-file bin/test-email-v2-track3.php` (foundation tests)
   - After Track 1 Task 4 (hydrate_context cycle_id): run `wp hl-core smoke-test`
   - After Track 2 Task A (scaffolding): run renderer fixture tests

4. **Cross-track handoffs:** When dispatching Track 1 subagents after Track 3 has landed, always include:
   > "Track 3 Tasks 1, 2, 5, 23 are committed. `HL_Roles` helper exists at `includes/services/class-hl-roles.php` with `has_role($stored, $role)` (format-agnostic). `HL_Audit_Service::get_last_event($entity_id, $action_type)` is available. `HL_Email_Condition_Evaluator` routes role checks through `HL_Roles`. Use these helpers, don't recreate them."

---

## Per-Task Quality Gate (Multi-Agent Review Loop)

For each task (or task group), the orchestrator must run this loop BEFORE committing:

**Phase A — Implementation:** Dispatch the subagent (or execute inline) to implement the task.

**Phase B — Initial review (2 agents in parallel):**
Launch 2 review agents with different expertise lenses:
- **Agent 1:** Domain expertise matching the task (e.g., for Track 1 UI tasks: Admin UX + WordPress admin conventions; for Track 3 SQL tasks: SQL + query performance)
- **Agent 2:** Complementary expertise (e.g., for Track 1: Security + accessibility; for Track 3: Schema integrity + idempotency)

Both agents receive the diff of the implementation + the task definition. They review and report:
- Bugs / logic errors
- Missing edge cases
- Convention deviations
- Specific fixes with file:line targets

**Phase C — Cross-review debate:**
The orchestrator passes each agent's report to the other. Each agent responds with agree / disagree / refine on the other's findings. If they disagree, they debate (via orchestrator relaying messages) until consensus is reached.

**Phase D — Senior expert scoring (2 more agents in parallel):**
After Phase C consensus, launch 2 additional senior expert agents:
- **Top Sr. Software Engineer** — scores code quality, logic, best practices, error-handling, maintainability (0-10 per aspect)
- **Top WordPress Expert Developer** — scores WP conventions, hook usage, nonce/capability discipline, `$wpdb->prepare` correctness, WP coding standards (0-10 per aspect)

Both receive the implementation diff + Phase C consensus. They provide scored feedback. If they disagree on any aspect, they debate via orchestrator until unified.

**Phase E — Feedback loop to original implementer:**
Orchestrator passes the Phase D unified feedback to a fresh implementation subagent (or back to the inline task). The implementer applies fixes OR pushes back with reasoning if they disagree.

- If implementer pushes back: orchestrator passes the pushback to the Phase D expert pair, who either accept the pushback as valid or double down with clarification. Iterate until resolved.
- If implementer accepts: apply fixes.

**Phase F — Strict senior engineer question:**
Ask the implementer: *"What would a team of very strict and picky Sr. Software Engineers say about this implementation?"*
If the implementer surfaces any concerns, they must fix them before proceeding.

**Phase G — Error-likelihood gate:**
Ask the implementer: *"What's the likelihood (0-10) that users encounter errors if we ship this as-is?"*
- If > 0: they must name the specific concerns and fix them. Iterate until the answer is 0.
- If 0: proceed to commit.

**Phase H — Commit + build journal update.** Only after Phase G returns 0.

**Exception — small tasks (under 20 lines changed):** Phases B-D can be collapsed into a single "code-reviewer" subagent with both lenses combined. Phases E-G still mandatory.

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
