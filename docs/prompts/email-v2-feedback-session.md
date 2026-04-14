# Session Prompt: Email V2 — Post-Demo Feedback Analysis & Fixes

## Context

I (Mateo) demoed the new Email V2 module to our clients Yuyan Huang and Christopher Love on 2026-04-13. The demo went well overall, but several gaps, bugs, and change requests were identified. I've already extracted everything from the transcript into a structured document at `docs/2026-04-13-LMS-Email-Module-Feedback.md`.

**Your job is to analyze the feedback against the actual codebase and tell me what needs to change — but you must NOT try to hold the entire codebase in your head. Follow the strategy below exactly.**

---

## STRATEGY: Two-Wave Divide-and-Conquer

The feedback doc has ~25 items across 7 sections. The codebase has 12+ relevant files. Reading everything in one pass WILL degrade your analysis quality. Instead, you will run two waves of parallel agents: first analysis, then independent verification.

### Step 1: Read ONLY the lightweight orientation files (you, the orchestrator)

Read these and ONLY these yourself:
- `STATUS.md` (per mandatory workflow rules)
- `docs/2026-04-13-LMS-Email-Module-Feedback.md` (the feedback — ~190 lines)

Do NOT read any PHP or JS files yourself. You are the orchestrator, not the analyst.

### Step 2 — Wave 1: Dispatch 4 focused analysis agents IN PARALLEL

Each agent gets a NARROW scope: a specific subset of feedback items and ONLY the files needed to analyze them. Each agent must cite specific line numbers, function names, and DB columns — no vague claims.

**Agent 1: "Trigger & Cron Engine Analyst"**
- Feedback items: A.1, A.3, A.4, A.5, A.9, G.2, G.3
- Files to read:
  - `includes/services/class-hl-email-automation-service.php` (the FULL file — this is the core)
  - `includes/domain/class-hl-component.php` (for date field definitions)
  - `includes/class-hl-installer.php` (ONLY the sections defining `hl_email_workflow` and `hl_component` table schemas — use grep to find them, don't read the whole file)
- Questions to answer:
  - List every hardcoded trigger key (e.g., `cron:cv_window_7d`) and what day-count it uses
  - What date fields does each trigger currently reference? (display_window_start? complete_by? something else?)
  - Does the `hl_component` table have a `release_date` or `available_from` column? (answers G.2)
  - How does the system currently scope workflows — per-cycle, per-partnership, or globally? (validates A.9)
  - Does a "coaching session not yet scheduled" condition exist in the condition evaluator? (A.5)
  - What DB schema changes would be needed to make day-offsets dynamic? (A.3)

**Agent 2: "Admin UI & Workflow Editor Analyst"**
- Feedback items: A.2, A.6, A.7, A.8, C.1, C.4, G.8
- Files to read:
  - `includes/admin/class-hl-admin-emails.php` (workflow list page, form fields)
  - `assets/js/admin/email-workflow.js` (workflow editor front-end)
  - `includes/admin/class-hl-admin-pathways.php` (ONLY search for "submission_window" — to understand A.6)
  - `includes/services/class-hl-email-condition-evaluator.php` (for A.2 — what condition options exist)
- Questions to answer:
  - What enrollment status options are currently in the condition dropdown? What's wrong with them? (A.2)
  - Where is the "submission window" field rendered in the UI? Which files? (A.6)
  - Is there any folder/group concept in the workflow list? (A.7)
  - Is there a clone/duplicate action for workflows? (A.8)
  - Is there a test-send toggle or email sending safety gate? (C.1)
  - What permission/capability does the workflow editor require? (C.4/G.8)

**Agent 3: "Template Builder & Renderer Analyst"**
- Feedback items: B.1, B.2, B.3, B.4, G.6, G.7
- Files to read:
  - `includes/admin/class-hl-admin-email-builder.php` (builder UI)
  - `assets/js/admin/email-builder.js` (builder JS — preview, merge tag insertion)
  - `includes/services/class-hl-email-merge-tag-registry.php` (what tags are registered)
  - `includes/services/class-hl-email-block-renderer.php` (HTML rendering — for white font bug)
- Questions to answer:
  - What merge tags exist for coaching sessions? List them exactly. (B.4, G.7)
  - Is there a merge tag insertion UI for the button URL field, or only for the body? (B.3)
  - Where does the preview rendering happen? Is there a font-color or CSS issue visible in the code? (B.1, G.6)
  - How does preview data population work? Can it select a specific entity for preview? (B.2)

**Agent 4: "Design Spec & Plan Verifier"**
- Feedback items: cross-cutting validation
- Files to read:
  - `docs/superpowers/specs/2026-04-10-email-system-v2-design.md`
  - `docs/superpowers/plans/2026-04-11-email-v2-track1-admin-ux.md`
  - `docs/superpowers/plans/2026-04-11-email-v2-track2-builder.md`
  - `docs/superpowers/plans/2026-04-11-email-v2-track3-backend.md`
- Questions to answer:
  - Were any of the feedback items (A.1-B.4) already anticipated in the original design but deferred?
  - Are there TODO comments or "Phase 2" notes in the specs that match the feedback?
  - Does the original design contradict any of the feedback requests?
  - Were folders, cloning, or dynamic day-offsets part of the original scope?

### Step 3 — Wave 2: Dispatch 2 cross-examination agents IN PARALLEL

These agents run AFTER Wave 1 completes. Each one independently reads the SAME code files as its pair of Wave 1 agents, plus reads the Wave 1 agents' output. Their job is NOT to correct — it's to produce a **discrepancy report.**

**Agent 5: "Cross-Examiner for Backend & UI" (reviews Agent 1 + Agent 2 findings)**
- Read the same files Agent 1 and Agent 2 read (listed above)
- Read Agent 1's and Agent 2's full output reports
- Produce a discrepancy report with 3 sections:
  1. **"I found something different"** — Specific claims from Agent 1 or 2 that you can't verify or that contradict what you see in the code. Cite the agent's claim, then cite what you actually found (with line numbers).
  2. **"They missed this"** — Things you found in the code that are relevant to feedback items A.1-A.9, C.1, C.4, G.2, G.3, G.8 that neither agent mentioned.
  3. **"I agree with everything else"** — Explicit confirmation of which claims you verified.
- Do NOT rewrite their analysis. Only report discrepancies and gaps.

**Agent 6: "Cross-Examiner for Builder & Specs" (reviews Agent 3 + Agent 4 findings)**
- Read the same files Agent 3 and Agent 4 read (listed above)
- Read Agent 3's and Agent 4's full output reports
- Produce a discrepancy report with the same 3 sections:
  1. **"I found something different"**
  2. **"They missed this"**
  3. **"I agree with everything else"**
- Do NOT rewrite their analysis. Only report discrepancies and gaps.

### Step 4: Compile the final report (you, the orchestrator)

Now you have 6 agent outputs: 4 analyses + 2 discrepancy reports. Compile into a single structured report:

For each feedback item (A.1 through G.8):
| Item | Current State | What Needs to Change | Complexity | Confidence | Conflicts/Risks | In Original Spec? |

The **Confidence** column is key:
- ✅ **High** — Both the analyst and cross-examiner agree
- ⚠️ **Medium** — Cross-examiner found a discrepancy or gap; note what it was
- ❓ **Low** — Conflicting findings; flag for Mateo to verify manually

Also compile:
- **Corrections to the feedback doc** — anything that contradicts the transcript-derived items
- **Items the agents found that the feedback doc missed**
- **Resolved clarification questions** — Section G answers backed by code evidence
- **Unresolved questions** — things that genuinely need my input
- **Discrepancies between agents** — list them explicitly so I can adjudicate

### Step 5: Present and WAIT

Show me the compiled report. Do NOT start coding or planning. I need to confirm we're aligned first.

---

## Phase 2: Implementation Plan (only after I explicitly confirm Phase 1)

Once I confirm the analysis is correct, THEN create a detailed implementation plan. The plan should:
- Group related items that can be done together
- Identify the correct order of operations (what unblocks what)
- Separate HIGH priority blockers from everything else
- Estimate scope per task
- Flag decisions I need to make before you start

---

## Critical Rules

1. **Do NOT read PHP/JS files yourself.** You are the orchestrator. Your context is for compiling and reasoning, not storing code.
2. **Each agent must cite evidence.** "Line 723 defines `$daily_triggers` array with these values..." — not "the triggers appear to be hardcoded."
3. **If an agent can't find something, it says so.** "I searched for 'submission_window' in class-hl-admin-pathways.php and found 0 matches" is better than guessing.
4. **No coding in this session.** Analysis only. Implementation is a separate session after alignment.
5. **Assume the feedback doc may have errors.** It was extracted from an auto-generated transcript. If code contradicts the doc, trust the code and flag the discrepancy.
6. **Wave 2 agents must read the code independently.** They should form their own understanding FIRST, then compare against Wave 1 output. Do not prime them with "Agent 1 said X, check if that's right" — instead say "Read these files, answer these questions, then compare your answers to this other report."
