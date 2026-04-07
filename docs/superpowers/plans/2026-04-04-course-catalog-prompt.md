# Course Catalog — Developer Prompt (Terminal 1)

Copy everything below the line into a new Claude Code terminal.

---

## PROMPT START

You are implementing the **Course Catalog** module for the HL Core WordPress plugin. This is a multilingual course mapping system that links logical courses to their English, Spanish, and Portuguese LearnDash equivalents.

### Required Reading (Read ALL of these before writing any code)

1. **Design Spec:** `docs/superpowers/specs/2026-04-04-course-catalog-design.md` — The approved architecture. Every design decision is documented here.
2. **Implementation Plan:** `docs/superpowers/plans/2026-04-04-course-catalog.md` — Step-by-step tasks with code. Follow this plan task by task.
3. **Project Rules:** `CLAUDE.md` — Mandatory workflow rules, coding conventions, commit checklist.
4. **Deploy Guide:** `.claude/skills/deploy.md` — SSH commands, SCP deploy, WP-CLI verification.
5. **Architecture:** `.claude/skills/architecture.md` — Domain model, roles, existing patterns.
6. **Current Status:** `STATUS.md` — Build queue, what's done, what's next.

### How You Must Work

You are the **Developer**. A separate QA/Tech Lead session (Terminal 2) will review every task you complete. Your job is to implement well. Their job is to break it.

#### For each task:

1. **Before coding, launch 2 agents in parallel:**
   - **Agent A (Implementer):** Reads the plan task AND all related existing files (not just the ones mentioned in the plan — also files that interact with this code). Writes the code.
   - **Agent B (Assumption Hunter):** Reads the same files independently. Its ONLY job is to ask itself Socratic questions about the implementation:
     - "What does WordPress/PHP *actually* do here — not what we assume it does?"
     - "If I call this method with null, empty string, 0, or a non-existent ID — what happens at each line?"
     - "Is there anything in the plan's suggested code that might be wrong or based on incorrect assumptions about WordPress behavior?"
     - Agent B must READ actual codebase files and WordPress behavior (grep for patterns in existing repositories) to answer its own questions. Never assume — verify. **Agent B is allowed to question the plan itself.** If the plan's code has a bug, catch it now.

2. **Implement the task.** Incorporate Agent B's findings. If Agent B found issues with the plan's suggested code, deviate from the plan and document why.

3. **Self-check before declaring done:**
   - For every public method you wrote, mentally call it with: normal input, null, empty string, zero, and a value that already exists in the DB. What does each line return?
   - For every DB operation, write out the actual SQL string that gets generated. Not the PHP — the SQL.
   - Does every domain model class have a property for every DB column it touches?

4. **STOP and tell me: "Task N is complete. Ready for Terminal 2 review."** Do NOT proceed to the next task. I will paste Terminal 2's review feedback back to you.

5. **When you receive reviewer feedback:**
   - Do NOT immediately agree or disagree with everything.
   - Launch 2 agents that take **opposing positions** on each finding:
     - One argues the reviewer is right
     - One argues the reviewer is wrong
     - Both must cite actual code, WordPress docs, or PHP behavior as evidence — not opinions
   - For findings where both agents agree the reviewer is right → **fix immediately**
   - For findings where agents disagree → **formulate a specific question** back to the reviewer
   - For findings where both agents agree the reviewer is wrong → **explain WHY with a code trace**
   - Produce your response as a prompt I can paste into Terminal 2 (see format below)

#### Response format when replying to reviewer feedback:

```
## Developer Response to Review — Task N

### Agreed & Fixed
- [Issue]: [What you changed and why]

### Questions for Reviewer
- [Issue]: [Specific question that would resolve the disagreement]

### Pushback (with evidence)
- [Issue]: [Why you disagree, with code trace or documentation reference]

### Summary
[N] issues fixed, [N] questions pending, [N] pushbacks. Ready for re-review once questions are resolved.
```

### Available Skills and Plugins

Check what skills are available to you with the Skill tool and USE THEM:
- Use `superpowers:verification-before-completion` before claiming any task is done
- Use `superpowers:requesting-code-review` after completing major tasks
- Use `superpowers:systematic-debugging` if you encounter any bugs or unexpected behavior
- Use any other relevant skills you find — explore what's available

### Task Order

Follow the implementation plan (`docs/superpowers/plans/2026-04-04-course-catalog.md`) tasks in order:

1. **Task 1:** Domain Model + Repository (new files) ✅ DONE
2. **Task 2:** Installer — Table, Migrations, Seed Data ✅ DONE
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
- **Schema revision** system in `HL_Installer::maybe_upgrade()` — Tasks 1-2 bumped it to rev 30
- Follow existing patterns EXACTLY — read existing files before writing new ones
- **Do NOT use `ADD COLUMN IF NOT EXISTS`** — MySQL 5.7 incompatible. Use `SHOW COLUMNS` check pattern.
- **No Select2 or external JS libraries** — use the existing vanilla JS AJAX search pattern from `class-hl-admin-enrollments.php`

### What "Done" Means (for you, before Terminal 2 reviews)

- Code implements the task from the plan
- You've traced every public method mentally with normal and edge-case inputs
- You've written out the actual SQL for every DB operation
- You've checked that domain model properties match DB columns
- You've incorporated findings from Agent B (Assumption Hunter)
- If you deviated from the plan's code, you documented why
- You're ready to defend your implementation with evidence, not just "it looks right"
