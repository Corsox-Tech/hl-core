# Course Survey Builder — Design Spec

**Date:** 2026-04-15
**Status:** Approved (reviewed by 7-agent panel — 0/10 error likelihood)
**Scope:** General-purpose survey system for HL Core, starting with End of Course Survey

---

## 1. Problem Statement

Housman Learning currently uses a LearnDash Quiz ("End of Course Exam") as a satisfaction survey at the end of each course. This is problematic because:

- It lives inside LearnDash, making it hard for non-technical admins to edit
- It's coupled to LD's quiz system, which is designed for assessments, not feedback surveys
- There's no unified reporting across courses or languages
- Adding/modifying questions requires navigating LD's quiz editor

The goal is to move survey management entirely into HL Core with admin-editable questions, multilingual support, unified reporting, and zero per-course configuration.

## 2. Requirements

### Non-Negotiable
1. One survey definition used across all courses (no per-course copies)
2. Admins can edit questions, add/remove questions, and edit translations from WP Admin
3. Responses auto-detect which course they're about (no manual per-course config)
4. Reports unified across languages (always show English question text)
5. Works with EN/ES/PT translations — admin edits all three in one form
6. Zero admin setup when a new course is added to the catalog
7. Survey is mandatory — participants cannot skip and proceed to next course

### Design Decisions
- **Delivery:** Modal on Program Page + Dashboard (not dismissable)
- **Survey assignment:** Cycle-level (dropdown in Cycle editor)
- **Per-course opt-out:** `requires_survey` checkbox on Course Catalog entries
- **Translation:** Multi-column JSON (`text_en`/`text_es`/`text_pt` per question)
- **Versioning:** Survey locked when responses exist; duplicate to create new version. Response count computed (no cached flag).
- **Reporting:** Per-version (Option A — versions shown separately)
- **Naming:** Internal name (admin-facing) + Display name (participant-facing)
- **Future extensibility:** `survey_type` ENUM supports N survey definitions and types
- **Pending queue:** Dedicated `hl_pending_survey` table (not user_meta — avoids race conditions)

## 3. Survey Content (End of Course Survey - 2026)

**Display name (participant-facing):** "End of Course Survey"
**Internal name (admin-facing):** "End of Course Survey - 2026"

**Introduction text:** "Please fill out this end-of-course survey to let us know how we are doing and how we can better support you in the future!"

### Q1: Likert Scale (group: agreement_scale)

Instruction: "Please indicate your level of agreement with each statement based on your experiences with this course."

Scale: Strongly Disagree (1) | Disagree (2) | No Opinion (3) | Agree (4) | Strongly Agree (5)

| Key | Question Text (EN) |
|-----|-------------------|
| `time_worthwhile` | The time I spent on this course was worthwhile. |
| `prepared_to_apply` | I feel prepared to apply information from this course in real-world situations. |
| `emotional_development` | The strategies covered in this course will be useful in supporting my students' emotional development. |
| `emotional_wellbeing` | The strategies covered in this course will be useful in supporting my own emotional wellbeing. |
| `classroom_routine` | The strategies covered in this course will fit into my classroom routine. |
| `resources_supports` | I have the necessary resources and supports to implement the strategies covered in this course. |

### Q2: Open Text

| Key | Question Text (EN) |
|-----|-------------------|
| `liked_most` | What did you like the most about this e-learning course? |

### Q3: Open Text

| Key | Question Text (EN) |
|-----|-------------------|
| `could_improve` | How could this e-learning course be improved? |

## 4. Data Model

### Table: `hl_survey`

```sql
CREATE TABLE {prefix}hl_survey (
    survey_id       bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    survey_uuid     char(36) NOT NULL,
    internal_name   varchar(255) NOT NULL,
    display_name    varchar(255) NOT NULL,
    survey_type     enum('end_of_course') NOT NULL DEFAULT 'end_of_course',
    version         int unsigned NOT NULL DEFAULT 1,
    questions_json  longtext NOT NULL,
    scale_labels_json longtext DEFAULT NULL,
    intro_text_json longtext DEFAULT NULL COMMENT 'JSON: {en, es, pt} intro paragraph',
    group_labels_json longtext DEFAULT NULL COMMENT 'JSON: {group_key: {en, es, pt}} instruction per question group',
    status          enum('draft','published') NOT NULL DEFAULT 'draft',
    created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (survey_id),
    UNIQUE KEY survey_uuid (survey_uuid),
    KEY idx_type_status (survey_type, status)
) $charset_collate;
```

**Note:** No `has_responses` column. Response existence is computed via `SELECT EXISTS(SELECT 1 FROM hl_course_survey_response WHERE survey_id = %d)` — eliminates cache sync bugs.

### Table: `hl_course_survey_response`

```sql
CREATE TABLE {prefix}hl_course_survey_response (
    response_id     bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    response_uuid   char(36) NOT NULL,
    survey_id       bigint(20) unsigned NOT NULL,
    user_id         bigint(20) unsigned NOT NULL,
    enrollment_id   bigint(20) unsigned NOT NULL,
    catalog_id      bigint(20) unsigned NOT NULL,
    cycle_id        bigint(20) unsigned NOT NULL,
    responses_json  longtext NOT NULL,
    language        varchar(5) NOT NULL DEFAULT 'en' COMMENT 'Snapshotted at submission time from enrollment.language_preference',
    submitted_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (response_id),
    UNIQUE KEY response_uuid (response_uuid),
    UNIQUE KEY one_per_enrollment_course_survey (enrollment_id, catalog_id, survey_id),
    KEY idx_survey (survey_id),
    KEY idx_cycle (cycle_id),
    KEY idx_catalog (catalog_id),
    KEY idx_user (user_id),
    KEY idx_submitted (submitted_at),
    KEY idx_survey_cycle (survey_id, cycle_id)
) $charset_collate;
```

### Table: `hl_pending_survey`

Replaces `user_meta` queue. Atomic INSERT/DELETE avoids race conditions on concurrent course completions.

```sql
CREATE TABLE {prefix}hl_pending_survey (
    pending_id      bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id         bigint(20) unsigned NOT NULL,
    survey_id       bigint(20) unsigned NOT NULL,
    enrollment_id   bigint(20) unsigned NOT NULL,
    catalog_id      bigint(20) unsigned NOT NULL,
    cycle_id        bigint(20) unsigned NOT NULL,
    component_id    bigint(20) unsigned NOT NULL,
    triggered_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (pending_id),
    UNIQUE KEY one_pending (enrollment_id, catalog_id, survey_id),
    KEY idx_user (user_id)
) $charset_collate;
```

**Note:** `component_id` stored directly so the submission handler can mark the correct component complete without re-deriving it. No `course_name` — derive from `catalog_id` at render time to avoid stale data.

### Existing Table Modifications

All added via `migrate_*` methods with `SHOW COLUMNS LIKE` guard + `ALTER TABLE` (not dbDelta). Follows established codebase pattern.

**`hl_course_catalog`** — new column:
```sql
ALTER TABLE {prefix}hl_course_catalog ADD COLUMN requires_survey tinyint(1) NOT NULL DEFAULT 1;
```

**`hl_cycle`** — new column:
```sql
ALTER TABLE {prefix}hl_cycle ADD COLUMN survey_id bigint(20) unsigned DEFAULT NULL;
```

**`hl_component_state`** — ENUM extension:
```sql
ALTER TABLE {prefix}hl_component_state MODIFY COLUMN completion_status
    enum('not_started','in_progress','complete','survey_pending')
    NOT NULL DEFAULT 'not_started';
```

### `questions_json` Structure

```json
[
  {
    "question_key": "time_worthwhile",
    "type": "likert_5",
    "required": true,
    "group": "agreement_scale",
    "text_en": "The time I spent on this course was worthwhile.",
    "text_es": "El tiempo que dedique a este curso valio la pena.",
    "text_pt": "O tempo que dediquei a este curso valeu a pena."
  },
  {
    "question_key": "liked_most",
    "type": "open_text",
    "required": true,
    "group": null,
    "text_en": "What did you like the most about this e-learning course?",
    "text_es": "Que fue lo que mas le gusto de este curso en linea?",
    "text_pt": "O que voce mais gostou neste curso online?"
  }
]
```

### `scale_labels_json` Structure

```json
{
  "likert_5": {
    "1": {"en": "Strongly Disagree", "es": "Totalmente en Desacuerdo", "pt": "Discordo Totalmente"},
    "2": {"en": "Disagree", "es": "En Desacuerdo", "pt": "Discordo"},
    "3": {"en": "No Opinion", "es": "Sin Opinion", "pt": "Sem Opiniao"},
    "4": {"en": "Agree", "es": "De Acuerdo", "pt": "Concordo"},
    "5": {"en": "Strongly Agree", "es": "Totalmente de Acuerdo", "pt": "Concordo Totalmente"}
  }
}
```

### `responses_json` Structure (on response table)

```json
{
  "time_worthwhile": 5,
  "prepared_to_apply": 4,
  "emotional_development": 5,
  "emotional_wellbeing": 4,
  "classroom_routine": 3,
  "resources_supports": 4,
  "liked_most": "I loved the video examples and practical strategies.",
  "could_improve": "More time for group discussion would be helpful."
}
```

### Seed Data

Idempotent: check `WHERE internal_name = %s` before INSERT. Safe to re-run on activation/upgrade.

**Survey 1:** "End of Course Survey - 2025"
- `internal_name`: "End of Course Survey - 2025"
- `display_name`: "End of Course Survey"
- `survey_type`: "end_of_course"
- `version`: 1
- `status`: "draft" (placeholder for future historical import)
- `questions_json`: `[]` (empty)

**Survey 2:** "End of Course Survey - 2026"
- `internal_name`: "End of Course Survey - 2026"
- `display_name`: "End of Course Survey"
- `survey_type`: "end_of_course"
- `version`: 2
- `status`: "published"
- `questions_json`: Full 8-question JSON as specified in Section 3

## 5. Frontend / Delivery

### Trigger Flow

1. Participant completes last LD lesson -> `learndash_course_completed` fires
2. HL Core's `on_course_completed()` resolves `catalog_id` (existing logic)
3. New checks:
   a. Does this cycle have a `survey_id`?
   b. Does this catalog entry have `requires_survey = 1`?
   c. Is the linked survey `status = 'published'`? (If not, fall through to normal completion)
4. If all yes -> check if `hl_course_survey_response` exists for `enrollment_id + catalog_id + survey_id`
5. If no response -> **write `completion_status = 'survey_pending'`, `completion_percent = 100`** to component_state -> INSERT into `hl_pending_survey` table
6. If response exists (re-completion edge case) -> mark component complete as normal

**Admin manual completions:** The admin "Mark Component Complete" action in the enrollment controller bypasses the survey gate intentionally (it is an administrative override). An audit event `survey_gate.admin_bypass` is logged when completing a component that has a required survey.

### Pending Survey Queue

Stored in `hl_pending_survey` table (atomic INSERT/DELETE, no race conditions):

```sql
INSERT INTO hl_pending_survey (user_id, survey_id, enrollment_id, catalog_id, cycle_id, component_id)
VALUES (%d, %d, %d, %d, %d, %d);
```

Course name is derived from `catalog_id` at render time (not stored — avoids stale data).

Supports multiple pending surveys (participant completes two courses before submitting either survey).

### Modal Loading (Cache-Safe)

The modal is loaded via AJAX to avoid full-page cache serving stale user-specific state:

1. An empty `<div id="hl-survey-modal-shell"></div>` is rendered in `wp_footer` on Program Page and Dashboard
2. On `DOMContentLoaded`, JS fires an AJAX request to `hl_check_pending_surveys`
3. If pending surveys exist, the endpoint returns survey data (questions, labels, course name) for the oldest entry (FIFO)
4. JS populates the modal shell and displays it with a loading spinner during fetch

### Modal Accessibility

The modal is non-dismissable and MUST meet WCAG 2.1 AA:

- `role="dialog"` and `aria-modal="true"` on the modal container
- `aria-labelledby` pointing to the survey title element
- **Focus trap:** first/last focusable element sentinel loop. Tab wraps within the modal.
- `inert` attribute set on all sibling containers of the modal (prevents tabbing behind)
- `Escape` key intercepted — does NOT close the modal. Refocuses the first element.
- **Likert groups:** `<fieldset>` + `<legend>` (question text) + visually hidden `<input type="radio">` + `<label>` styled as pill. `role="radiogroup"`, roving tabindex, arrow-key Left/Right navigation.
- `aria-live="polite"` region for validation errors so screen readers announce them
- Minimum touch target: 44x44px on all interactive elements (iOS guideline)

### Modal Render Order

1. Intro paragraph (from `intro_text_json` in user's language)
2. Per question group: group instruction label (from `group_labels_json`) as styled subheading
3. Questions within group, in display order from `questions_json` array
4. Submit button at bottom

### Modal Characteristics

- Not dismissable (no X/close button) — survey is mandatory
- Likert items use pill/button style (matching HL Core form patterns)
- **Mobile responsive:** below 576px, Likert pills stack vertically (full-width) instead of horizontal row. Matches BuddyBoss Bootstrap breakpoints.
- Language from `enrollment.language_preference`
- Course name derived from `catalog_id` at render time
- Client-side validation: all required fields filled before submit enables
- **Submit button:** disabled on click, text changes to "Submitting..." with spinner, re-enabled only on AJAX failure

### AJAX Security

- Nonce: `wp_nonce_field('hl_survey_submit_' . $pending_id)` rendered in modal
- Handler: `check_ajax_referer('hl_survey_submit_' . $pending_id)` + `is_user_logged_in()` gate
- Capability: user must be the owner of the `enrollment_id` referenced

### Server-Side Response Validation

Before inserting `responses_json`, the handler MUST:
1. `json_decode()` the payload — reject invalid JSON
2. Validate all keys exist in the survey's `questions_json` — reject unknown keys
3. For `likert_5` type: enforce value is integer 1-5
4. For `open_text` type: `wp_kses($value, array())` to strip HTML/scripts
5. Reject payloads exceeding 64KB
6. Re-encode with `json_encode()` for storage (sanitized)

### Double-Submit Handling

Multi-tab scenario: Tab A submits (succeeds), Tab B submits (UNIQUE constraint fires).

The AJAX handler MUST:
1. Attempt INSERT
2. If `$wpdb->last_error` contains 'Duplicate entry': query existing response to confirm it exists
3. Return success-equivalent JSON (not an error): `{"status": "already_submitted", "message": "Survey already submitted"}`
4. Delete the `hl_pending_survey` row (idempotent)
5. Ensure component_state is 'complete' (idempotent)
6. JS closes modal gracefully

### Error Handling

| Scenario | Behavior |
|----------|----------|
| AJAX submit fails (network/server) | Error message in modal: "Something went wrong. Please try again." Retry button. Submit button re-enabled. Modal stays open. |
| User closes browser | Pending survey row persists in DB. Modal re-appears on next visit via AJAX check. |
| User navigates away | Same — pending row intact, modal returns on next Program Page / Dashboard load. |
| Two courses complete simultaneously | Two atomic INSERTs into `hl_pending_survey`. No race condition. Shown one at a time (FIFO). |
| Admin manual completion | Survey gate bypassed. Audit event logged. Component marked complete directly. |
| Survey unpublished while pending | AJAX check validates survey.status='published'. If not: deletes pending row, writes component_state to 'complete'/100, returns no-survey response. Modal never shown. |

### Program Page Component State

When survey is pending for a course component:
- `completion_status = 'survey_pending'` with `completion_percent = 100`
- Status badge: **"Survey Pending"** (amber/orange, `hl-pp-overlay-survey-pending` class)
- Translated sub-label: `__('Complete the survey to finish this course', 'hl-core')`
- Clicking component opens the survey modal directly
- After submission: component_state updated to 'complete'/100, badge flips to green
- **Rollup behavior:** `survey_pending` is treated as NOT complete for prerequisite checking and "completed components" counts, but `completion_percent = 100` is accurate (course content IS done)

## 6. Admin UI

### Location

New **"Course Surveys"** tab on existing Instruments admin page (`HL_Admin_Instruments`).

### List View

| Column | Content |
|--------|---------|
| Internal Name | "End of Course Survey - 2026" |
| Display Name | "End of Course Survey" |
| Type | End of Course |
| Version | 2 |
| Status | Draft / Published (pill badge) |
| Responses | Count (computed, not cached) |
| Used By | Cycle names using this survey |
| Actions | Edit, Duplicate, Preview |

- **Edit** — opens editor. Disabled if responses exist (computed check). Shows lock notice with response count.
- **Duplicate as New Version** — clones to draft. Available when locked. See Duplicate Workflow below.
- **Preview** — read-only modal showing participant view. Renders in current WPML locale. Admin note: "Switch site language to preview other translations."

### Editor Form

**Top section:**
- Internal Name (text)
- Display Name (text)
- Type (dropdown — End of Course, extensible)
- Status (Draft / Published)

**Questions section:**
- Ordered rows, each with:
  - Question key (auto-slug from English text, editable)
  - Type: Likert (5-point) / Open Text / Yes-No
  - Required checkbox
  - Group field (shared instruction header key)
  - Three text fields: **EN** | **ES** | **PT**
- Add Question, drag reorder, delete per question

**Scale Labels section** (collapsible):
- EN/ES/PT columns per scale value

**Locked state** (responses exist — computed):
- Read-only with banner: "This survey has X responses and is locked."
- "Duplicate as New Version" button
- "Delete Responses" button with two-step confirmation: "Are you sure? This will permanently delete X responses." + 3-second delay on confirm button.

### Duplicate Workflow

When "Duplicate as New Version" is clicked:

**Cloned (copied as-is):**
- `questions_json`, `scale_labels_json`, `intro_text_json`, `group_labels_json`
- `survey_type`, `display_name`

**Reset (new values):**
- `survey_id`: new auto-increment
- `survey_uuid`: new UUID generated
- `internal_name`: original + " (Copy)"
- `status`: `draft`
- `version`: MAX(version) + 1 for that survey_type
- `created_at`: now
- `updated_at`: null

**NOT cloned:** responses, cycle assignments.

### Cycle Editor Integration

New dropdown: **"End of Course Survey"** — lists all published surveys. Nullable (null = no survey for this cycle).

**Warning on change:** If changing `survey_id` on a cycle that has existing survey responses, show confirmation dialog: "This cycle has X survey responses under the current survey. Changing the survey will not delete existing responses but new completions will use the new survey. Continue?" Change is allowed but audit-logged as `cycle.survey_id_changed_with_responses`.

### Course Catalog Integration

Checkbox: **"Requires End of Course Survey"** on add/edit form. Default checked.

### Trigger Logic

Course completion fires survey when: `cycle.survey_id IS NOT NULL` **AND** `catalog.requires_survey = 1` **AND** `survey.status = 'published'`.

## 7. Reporting

### Location

New **"Course Surveys"** report section in Admin Reports.

### Summary View (default)

**Filters:** Survey dropdown, Cycle (multi-select), Partnership, School, Date range

**Summary cards:** Total Responses, Average Overall Agreement, Completion Rate

**Per-question breakdown:**

| Question | Str. Disagree | Disagree | No Opinion | Agree | Str. Agree | Mean |
|----------|:-:|:-:|:-:|:-:|:-:|:-:|
| Time worthwhile | 2% | 3% | 5% | 45% | 45% | 4.28 |
| Prepared to apply | 1% | 4% | 8% | 42% | 45% | 4.26 |

Open text questions show count with "View All" link.

**Per-course comparison:**

| Course | Responses | Mean Agreement | Lowest Scoring Question |
|--------|:-:|:-:|------------------------|
| TC1 | 42 | 4.31 | Resources & supports (3.89) |
| TC2 | 38 | 4.45 | Classroom routine (4.02) |

Sorted by mean ascending (weakest courses first).

### Open Text View

Accessed via "View All" on open text questions.

| Participant | School | Course | Response | Date |
|-------------|--------|--------|----------|------|
| Jane Smith | Lincoln Elementary | TC1 | "I loved the video examples..." | 2026-04-10 |

- English question text in headers regardless of submission language
- Responses shown as-is (in submitted language)
- Sortable, searchable

### CSV Export

Two header rows for readability:

**Row 1 (column keys):** Participant | Email | School | Cycle | Course Code | Course Title | Survey Version | Language | time_worthwhile | prepared_to_apply | ... | liked_most | could_improve | Submitted At

**Row 2 (question text):** | | | | | | | | The time I spent on this course was worthwhile. | I feel prepared to apply... | ... | What did you like the most... | How could this be improved... |

**Row 3+ (data):** One row per response.

- Column headers always English (from `questions_json` `text_en`)
- Likert values as numbers (1-5)
- Open text as-is (in submitted language)
- Respects active filters

### Cross-Version Reporting

- Survey dropdown filters by specific definition
- Each report shows data for one survey version
- No cross-version aggregation (per Option A decision)

### Delete Responses

- Checkbox per row in report view for selective deletion
- "Delete All Responses" on survey editor locked state
- Two-step confirmation with delay (not "type DELETE")
- `manage_hl_core` capability required
- Audit logged

## 8. Class Structure

### New Files

| File | Class | Responsibility |
|------|-------|---------------|
| `includes/domain/class-hl-survey.php` | `HL_Survey` | Domain model — properties, JSON parsing, language resolution |
| `includes/domain/repositories/class-hl-survey-repository.php` | `HL_Survey_Repository` | CRUD for `hl_survey` table only |
| `includes/domain/repositories/class-hl-survey-response-repository.php` | `HL_Survey_Response_Repository` | CRUD for `hl_course_survey_response` + `hl_pending_survey` tables, reporting queries |
| `includes/services/class-hl-survey-service.php` | `HL_Survey_Service` | Trigger logic, pending queue, submission, validation, completion gate |
| `includes/frontend/class-hl-frontend-survey-modal.php` | `HL_Frontend_Survey_Modal` | Modal shell renderer, AJAX endpoints (check pending, submit, validate) |
| `includes/admin/class-hl-admin-survey.php` | `HL_Admin_Survey` | Survey editor tab (list, edit, duplicate, preview, delete responses) |
| `includes/admin/class-hl-admin-survey-reports.php` | `HL_Admin_Survey_Reports` | Report views (summary, open text, per-course), CSV export |

### Modified Files

| File | Change |
|------|--------|
| `class-hl-installer.php` | 3 new tables, 2 new columns, 1 ENUM extension, schema revision, seed data, migrate_* methods |
| `class-hl-learndash-integration.php` | Survey gate in `on_course_completed()` — check requirements, write survey_pending state, insert pending row |
| `class-hl-admin-instruments.php` | "Course Surveys" tab |
| `class-hl-admin-cycle-editor.php` | Survey dropdown with change-warning |
| `class-hl-admin-course-catalog.php` | "Requires Survey" checkbox |
| `class-hl-admin-enrollments.php` | Admin manual-complete bypass + audit event |
| `class-hl-frontend-program-page.php` | `survey_pending` overlay class + translated sub-label + modal trigger |
| `class-hl-frontend-dashboard.php` | Modal shell in footer + AJAX trigger |
| `class-hl-reporting-service.php` | Rollup logic: treat `survey_pending` as not-complete for counts |
| `class-hl-rules-engine-service.php` | Prerequisite check: `survey_pending` blocks next component |
| `assets/css/frontend.css` | Modal styles (z-index: 100001/100002), pill Likert buttons, responsive 576px stack |
| `assets/css/admin.css` | Survey editor, report table styles |
| `assets/js/frontend.js` | AJAX pending check, modal population, focus trap, submit handler, double-submit guard |

### Estimated Scope

- 7 new PHP files
- ~13 modified files
- ~1500-1800 lines of new code
- 3 new DB tables + 2 new columns + 1 ENUM extension
- 1 schema revision bump
