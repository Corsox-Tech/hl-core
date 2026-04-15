# Course Survey Builder — Design Spec

**Date:** 2026-04-15
**Status:** Approved
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
- **Versioning:** Survey locked when responses exist; duplicate to create new version
- **Reporting:** Per-version (Option A — versions shown separately)
- **Naming:** Internal name (admin-facing) + Display name (participant-facing)
- **Future extensibility:** `survey_type` field supports N survey definitions and types

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
    survey_type     varchar(50) NOT NULL DEFAULT 'end_of_course',
    questions_json  longtext NOT NULL,
    scale_labels_json longtext DEFAULT NULL,
    intro_text_json longtext DEFAULT NULL COMMENT 'JSON: {en, es, pt} intro paragraph',
    group_labels_json longtext DEFAULT NULL COMMENT 'JSON: {group_key: {en, es, pt}} instruction per question group',
    status          enum('draft','published') NOT NULL DEFAULT 'draft',
    has_responses   tinyint(1) NOT NULL DEFAULT 0,
    created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (survey_id),
    UNIQUE KEY survey_uuid (survey_uuid),
    KEY idx_type_status (survey_type, status)
) $charset_collate;
```

### Table: `hl_course_survey_response`

```sql
CREATE TABLE {prefix}hl_course_survey_response (
    response_id     bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    response_uuid   char(36) NOT NULL,
    survey_id       bigint(20) unsigned NOT NULL,
    enrollment_id   bigint(20) unsigned NOT NULL,
    catalog_id      bigint(20) unsigned NOT NULL,
    cycle_id        bigint(20) unsigned NOT NULL,
    responses_json  longtext NOT NULL,
    language        varchar(5) NOT NULL DEFAULT 'en',
    submitted_at    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (response_id),
    UNIQUE KEY response_uuid (response_uuid),
    UNIQUE KEY one_per_enrollment_course (enrollment_id, catalog_id),
    KEY idx_survey (survey_id),
    KEY idx_cycle (cycle_id),
    KEY idx_catalog (catalog_id),
    KEY idx_submitted (submitted_at)
) $charset_collate;
```

### Existing Table Modifications

**`hl_course_catalog`** — new column:
```sql
requires_survey tinyint(1) NOT NULL DEFAULT 1
```

**`hl_cycle`** — new column:
```sql
survey_id bigint(20) unsigned DEFAULT NULL
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

**Survey 1:** "End of Course Survey - 2025"
- `internal_name`: "End of Course Survey - 2025"
- `display_name`: "End of Course Survey"
- `survey_type`: "end_of_course"
- `status`: "draft" (placeholder for future historical import)
- `questions_json`: `[]` (empty)

**Survey 2:** "End of Course Survey - 2026"
- `internal_name`: "End of Course Survey - 2026"
- `display_name`: "End of Course Survey"
- `survey_type`: "end_of_course"
- `status`: "published"
- `questions_json`: Full 8-question JSON as specified in Section 3

## 5. Frontend / Delivery

### Trigger Flow

1. Participant completes last LD lesson -> `learndash_course_completed` fires
2. HL Core's `on_course_completed()` resolves `catalog_id` (existing logic)
3. New check: does this cycle have a `survey_id`? Does this catalog entry have `requires_survey = 1`?
4. If yes -> check if `hl_course_survey_response` exists for `enrollment_id + catalog_id`
5. If no response -> **do not mark HL component as complete** -> push to pending survey queue in `user_meta`
6. If response exists (re-completion edge case) -> mark component complete as normal

### Pending Survey Queue

Stored in `user_meta` key `_hl_pending_surveys` as JSON array:

```json
[
  {
    "survey_id": 2,
    "catalog_id": 14,
    "enrollment_id": 87,
    "cycle_id": 5,
    "course_name": "Teacher Course 1",
    "triggered_at": "2026-04-15 10:30:00"
  }
]
```

Supports multiple pending surveys (participant completes two courses before submitting).

### Modal Behavior

**Trigger pages:** Program Page, Dashboard

**On page load:**
1. Check `_hl_pending_surveys` user meta
2. If non-empty -> render modal for oldest entry (FIFO)
3. After submission -> remove entry, show next on next page load if more remain

**Modal characteristics:**
- Not dismissable (no X/close button) — survey is mandatory
- Likert items use pill/button style (matching HL Core form patterns)
- Language from `enrollment.language_preference`
- Course name from pending queue entry
- Validation: all required fields filled before submit enables
- AJAX submission -> insert response, mark HL component complete, remove from queue, close modal with success toast, page refresh

### Error Handling

| Scenario | Behavior |
|----------|----------|
| AJAX submit fails | Error message in modal: "Something went wrong. Please try again." Retry button. Modal stays open. |
| User closes browser | Pending queue persists. Modal re-appears on next visit. |
| User navigates away | Same — pending queue intact, modal returns next page load. |
| Two courses complete quickly | Both added to queue. Shown one at a time (FIFO). |
| Admin/bulk course completion | `learndash_course_completed` may not fire. Acceptable — admin completions skip survey. |

### Program Page Component State

When survey is pending for a course component:
- Status badge: **"Survey Pending"** (amber/orange)
- Clicking component opens the survey modal directly
- After submission: flips to "Complete" (green)

## 6. Admin UI

### Location

New **"Course Surveys"** tab on existing Instruments admin page (`HL_Admin_Instruments`).

### List View

| Column | Content |
|--------|---------|
| Internal Name | "End of Course Survey - 2026" |
| Display Name | "End of Course Survey" |
| Type | End of Course |
| Status | Draft / Published (pill badge) |
| Responses | Count |
| Used By | Cycle names using this survey |
| Actions | Edit, Duplicate, Preview |

- **Edit** — opens editor (disabled if responses exist)
- **Duplicate as New Version** — clones to draft, available when locked
- **Preview** — read-only modal showing participant view

### Editor Form

**Top section:**
- Internal Name (text)
- Display Name (text)
- Type (dropdown — End of Course, extensible)
- Status (Draft / Published)

**Questions section:**
- Ordered rows, each with:
  - Question key (auto-slug, editable)
  - Type: Likert (5-point) / Open Text / Yes-No
  - Required checkbox
  - Group field (shared instruction header)
  - Three text fields: **EN** | **ES** | **PT**
- Add Question, drag reorder, delete per question

**Scale Labels section** (collapsible):
- EN/ES/PT columns per scale value

**Locked state** (has responses):
- Read-only with banner: "This survey has X responses and is locked."
- "Duplicate as New Version" button
- "Delete Responses" button (requires typing DELETE to confirm)

### Cycle Editor Integration

New dropdown: **"End of Course Survey"** — lists all published surveys. Nullable (null = no survey for this cycle).

### Course Catalog Integration

Checkbox: **"Requires End of Course Survey"** on add/edit form. Default checked.

### Trigger Logic

Course completion fires survey when: `cycle.survey_id IS NOT NULL` **AND** `catalog.requires_survey = 1`.

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

Flat structure, one row per response:

| Participant | Email | School | Cycle | Course Code | Course Title | Survey Version | Language | Q: Time Worthwhile | Q: Prepared to Apply | ... | Q: Liked Most | Q: Could Improve | Submitted At |

- Column headers always English
- Likert values as numbers (1-5)
- Open text as-is
- Respects active filters

### Cross-Version Reporting

- Survey dropdown filters by specific definition
- Each report shows data for one survey version
- No cross-version aggregation (per Option A decision)

### Delete Responses

- Checkbox per row in report view for selective deletion
- "Delete All Responses" on survey editor locked state
- Confirmation requires typing DELETE
- `manage_hl_core` capability required
- Audit logged

## 8. Class Structure

### New Files

| File | Class | Responsibility |
|------|-------|---------------|
| `includes/domain/class-hl-survey.php` | `HL_Survey` | Domain model |
| `includes/domain/repositories/class-hl-survey-repository.php` | `HL_Survey_Repository` | CRUD for both tables |
| `includes/services/class-hl-survey-service.php` | `HL_Survey_Service` | Trigger logic, pending queue, submission, completion gate |
| `includes/frontend/class-hl-frontend-survey-modal.php` | `HL_Frontend_Survey_Modal` | Modal renderer, AJAX endpoints |
| `includes/admin/class-hl-admin-survey.php` | `HL_Admin_Survey` | Survey editor tab |
| `includes/admin/class-hl-admin-survey-reports.php` | `HL_Admin_Survey_Reports` | Report views, CSV export |

### Modified Files

| File | Change |
|------|--------|
| `class-hl-installer.php` | 2 new tables, 2 new columns, schema revision, seed data |
| `class-hl-learndash-integration.php` | Survey gate in `on_course_completed()` |
| `class-hl-admin-instruments.php` | "Course Surveys" tab |
| `class-hl-admin-cycle-editor.php` | Survey dropdown |
| `class-hl-admin-course-catalog.php` | "Requires Survey" checkbox |
| `class-hl-frontend-program-page.php` | "Survey Pending" state + modal trigger |
| `class-hl-frontend-dashboard.php` | Modal trigger |
| `assets/css/frontend.css` | Modal styles, pill Likert buttons |
| `assets/css/admin.css` | Survey editor, report table styles |
| `assets/js/frontend.js` | Modal AJAX, validation |

### Estimated Scope

- 6 new PHP files
- ~8 modified files
- ~1200-1500 lines of new code
- 2 new DB tables + 2 new columns
- 1 schema revision bump
