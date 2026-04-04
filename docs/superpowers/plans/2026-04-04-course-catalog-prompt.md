# Course Catalog Implementation — Terminal Prompt

Copy everything below the line into a new Claude Code terminal.

---

## PROMPT START

You are implementing the **Course Catalog** module for the HL Core WordPress plugin. This is a multilingual course mapping system that links logical courses to their English, Spanish, and Portuguese LearnDash equivalents.

### Required Reading (Read ALL of these before writing any code)

1. **Design Spec:** `docs/superpowers/specs/2026-04-04-course-catalog-design.md` — The approved architecture. Every design decision is documented here. Do not deviate from it.
2. **Implementation Plan:** `docs/superpowers/plans/2026-04-04-course-catalog.md` — Step-by-step tasks with code. Follow this plan task by task.
3. **Project Rules:** `CLAUDE.md` — Mandatory workflow rules, coding conventions, commit checklist.
4. **Deploy Guide:** `.claude/skills/deploy.md` — SSH commands, SCP deploy, WP-CLI verification.
5. **Architecture:** `.claude/skills/architecture.md` — Domain model, roles, existing patterns.
6. **Current Status:** `STATUS.md` — Build queue, what's done, what's next.

### How You Must Work

**For EVERY task in the implementation plan, you MUST follow this multi-agent workflow:**

#### Phase 1: Pre-flight (before writing code)

Launch 2 agents in parallel:

- **Agent A (Implementer):** Reads the plan task, reads ALL relevant existing files (not just the ones in the plan — also related files that interact with this code), and writes the code.
- **Agent B (Pre-reviewer):** Reads the same files independently and produces a **checklist of specific runtime behaviors** to verify. Not vague ("handle edge cases") but concrete:
  - "What does `$wpdb->insert()` do when a PHP value is `null`? Does it produce SQL `NULL` or empty string `''`?"
  - "Does the domain model class have a property for every DB column? If not, the constructor's `property_exists()` will silently drop data."
  - "Does this static cache ever get reset? What if the first call returns false — is false cached permanently?"
  - Agent B must READ the WordPress/wpdb source behavior docs or grep for how other code in this codebase handles the same patterns. Do not assume — verify.

#### Phase 2: Execution tracing review (after code is written)

Launch 3 review agents in parallel. **Each agent must trace actual execution paths, not just read the code:**

- **Reviewer 1 (Runtime Trace):** For every public method in the new code, mentally execute it with: (a) a normal happy-path input, (b) null/empty input, (c) a value that exists in the DB. Write out what each line returns. Flag any line where the actual WordPress/PHP behavior differs from what the code assumes. **You MUST read the existing domain model classes (e.g., `class-hl-enrollment.php`, `class-hl-component.php`) to verify all referenced properties exist.**

- **Reviewer 2 (WordPress/DB Behavior):** Focus specifically on DB operations. For every `$wpdb->insert()`, `$wpdb->update()`, `$wpdb->prepare()` call:
  - What SQL does this actually generate? (WordPress `$wpdb->insert()` converts PHP `null` to `''` — this breaks UNIQUE constraints on nullable columns. Use raw `$wpdb->query()` with explicit `NULL` for nullable fields.)
  - Are format strings (`%s`, `%d`) correct for each parameter?
  - Do UNIQUE constraints work as intended with the actual SQL being generated?
  - Does `dbDelta()` handle the table definition correctly?

- **Reviewer 3 (Cross-file Integration):** Read every file that will interact with this new code in future tasks. Verify:
  - Are all properties defined on domain models that other code will reference?
  - Are method signatures compatible with how callers will use them?
  - Will the migration work on both MySQL 5.7 AND MariaDB?
  - Are there any naming inconsistencies between this code and the spec?

#### Phase 3: Fix and re-verify

Fix ALL issues found. Then launch 2 fresh agents (not the same ones) to verify the fixes:
- **Fix Verifier 1:** Re-reads the corrected code and confirms each reported issue is actually fixed (not just moved to a different line).
- **Fix Verifier 2:** Runs the same execution trace as Reviewer 1 on the corrected code to confirm the happy path and edge cases work.

#### Phase 4: Gate

**STOP and ask me before proceeding to the next task.** Say: "Task N is complete. All review agents approved. Here are the issues found and fixed: [list]. Ready for external review. Can I proceed to Task N+1?" **DO NOT continue until I say yes.** I need to have the other terminal review your work before you continue.

#### Critical Review Rules (these catch the bugs that slipped through)

- **Never assume WordPress/PHP default behavior.** If you're unsure what `$wpdb->insert()` does with `null`, READ the WordPress source or grep for how other repository files in this codebase handle nullable columns. The codebase has dozens of repositories — check how they handle NULL inserts.
- **Every domain model class must have a property for every DB column.** If the migration adds a column to a table, the corresponding domain model class MUST have a matching `public $property`. The constructor uses `property_exists()` — missing properties silently drop data.
- **Static caches that cache `false` or empty results are bugs.** Only cache truthy results, or use a sentinel value to distinguish "not loaded" from "loaded and empty."
- **Test nullable UNIQUE columns explicitly.** WordPress `$wpdb->insert()` will insert `''` (empty string) for PHP `null`, which violates UNIQUE constraints when multiple rows have the same empty string. Use `$wpdb->query()` with raw SQL and explicit `NULL` for nullable UNIQUE columns.

### Available Skills and Plugins

Check what skills are available to you with the Skill tool and USE THEM:
- Use `superpowers:verification-before-completion` before claiming any task is done
- Use `superpowers:requesting-code-review` after completing major tasks
- Use `superpowers:systematic-debugging` if you encounter any bugs or unexpected behavior
- Use any other relevant skills you find — explore what's available

### Task Order

Follow the implementation plan (`docs/superpowers/plans/2026-04-04-course-catalog.md`) tasks in order:

1. **Task 1:** Domain Model + Repository (new files)
2. **Task 2:** Installer — Table, Migrations, Seed Data
3. **Task 3:** Routing Service Refactor
4. **Task 4:** LearnDash Integration — Catalog-Aware Completion
5. **Task 5:** Admin Course Catalog Page
6. **Task 6:** Import Module — Language Column
7. **Task 7:** Enrollment Edit Form — Language Preference
8. **Task 8:** Pathway Admin — Catalog Dropdown
9. **Task 9:** Frontend Language Resolution
10. **Task 10:** Reporting — Catalog Titles
11. **Task 11:** Final Deploy + Verification

### Commit Rules

- Commit after each task (not after each step within a task)
- Follow the commit messages from the plan
- After the final task, update STATUS.md and README.md per CLAUDE.md rules
- Do NOT push to remote or deploy until I explicitly say to

### Key Technical Context

- **PHP 7.4+** with WordPress coding standards
- **No local PHP runtime** — you cannot run PHP locally. Verification happens on the test server after deploy.
- **Class prefix:** `HL_` — **Table prefix:** `hl_`
- **Singleton** pattern for admin pages, **Repository** pattern for DB access
- **Schema revision** system in `HL_Installer::maybe_upgrade()` — currently at rev 29, bump to 30
- Follow existing patterns EXACTLY — read existing files before writing new ones
- **Do NOT use `ADD COLUMN IF NOT EXISTS`** — MySQL 5.7 incompatible. Use `SHOW COLUMNS` check pattern.
- **No Select2 or external JS libraries** — use the existing vanilla JS AJAX search pattern from `class-hl-admin-enrollments.php`

### What "Perfect" Means

- Code matches the spec exactly — no additions, no omissions
- Follows existing codebase patterns identically (check real files, don't guess)
- All edge cases handled (null catalog, empty table, missing codes, concurrent edits)
- Audit logging on all mutations
- Nonce verification and capability checks on all admin actions
- Idempotent migrations (safe to re-run)
- Backward compatible (external_ref fallback works during migration)

### Start Now

Read the spec, read the plan, read CLAUDE.md, then begin Task 1. Launch your implementer and pre-reviewer agents. Stop after Task 1 is fully reviewed and approved by all agents, and ask me before proceeding.
