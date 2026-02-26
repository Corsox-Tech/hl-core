# Phase 23: Child Assessment Restructure — Claude Code Prompts

Each prompt is self-contained. Execute in order. Commit after each.

---

## 23.1 — DB Migration + Age Group Constants (use Opus)

```
Read CLAUDE.md, README.md, and docs/CHILD_ASSESSMENT_RESTRUCTURE.md first.

PHASE 23.1: Database migration + age group constants.

1. Create a utility class or add to an existing utils file: `HL_Age_Group_Helper`
   Static methods:
   - `get_age_ranges()` → returns array of age group definitions:
     - 'infant' => ['min_months' => 0, 'max_months' => 11, 'instrument_type' => 'children_infant']
     - 'toddler' => ['min_months' => 12, 'max_months' => 35, 'instrument_type' => 'children_toddler']
     - 'preschool' => ['min_months' => 36, 'max_months' => 59, 'instrument_type' => 'children_preschool']
     - 'k2' => ['min_months' => 60, 'max_months' => null, 'instrument_type' => 'children_k2']
   - `calculate_age_group($dob, $reference_date = null)` → returns age group string based on DOB
     If reference_date is null, use current date. Calculate age in months, return matching group.
   - `calculate_age_months($dob, $reference_date = null)` → returns int age in months
   - `get_instrument_type_for_age_group($age_group)` → returns instrument_type string

2. In class-hl-installer.php, add migration `migrate_child_assessment_restructure()`:

   a. CREATE TABLE `hl_child_track_snapshot`:
      - snapshot_id BIGINT UNSIGNED AUTO_INCREMENT PK
      - child_id BIGINT UNSIGNED NOT NULL
      - track_id BIGINT UNSIGNED NOT NULL
      - frozen_age_group VARCHAR(20) NOT NULL
      - dob_at_freeze DATE
      - age_months_at_freeze INT
      - frozen_at DATETIME NOT NULL
      - UNIQUE KEY (child_id, track_id)
      - INDEX (track_id)

   b. ALTER `hl_child_classroom_current`:
      - ADD status ENUM('active','teacher_removed') NOT NULL DEFAULT 'active'
      - ADD removed_by_enrollment_id BIGINT UNSIGNED NULL
      - ADD removed_at DATETIME NULL
      - ADD removal_reason ENUM('left_school','moved_classroom','other') NULL
      - ADD removal_note TEXT NULL
      - ADD added_by_enrollment_id BIGINT UNSIGNED NULL
      - ADD added_at DATETIME NULL
      - ADD INDEX (status)

   c. ALTER `hl_child_assessment_childrow`:
      - ADD status ENUM('active','skipped','not_in_classroom','stale_at_submit') NOT NULL DEFAULT 'active'
      - ADD skip_reason VARCHAR(255) NULL
      - ADD frozen_age_group VARCHAR(20) NULL
      - ADD instrument_id BIGINT UNSIGNED NULL

   d. ALTER `hl_child_assessment_instance`:
      - Make instrument_id nullable (ALTER COLUMN instrument_id ... NULL) if not already

   Also update create_tables() for new installs. Bump schema revision.
   Wrap all ALTERs in column-existence checks.

Commit: "db: Phase 23.1 — child assessment restructure migration + age group helper"
```

---

## 23.2 — Snapshot Service + Freeze Logic (use Opus)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phase 23.1 is complete.

PHASE 23.2: Snapshot service for freezing child age groups per track.

1. Create `HL_Child_Snapshot_Service` (new file in includes/services/):

   Methods:
   - `freeze_age_groups($track_id, $reference_date = null)`
     For every child in classrooms linked to this track (via teaching assignments → classrooms → children):
     - Skip if snapshot already exists for (child_id, track_id)
     - Calculate age group from child DOB using HL_Age_Group_Helper
     - INSERT into hl_child_track_snapshot
     - Returns count of newly frozen snapshots
     
   - `get_frozen_age_group($child_id, $track_id)`
     Returns the frozen_age_group string, or null if no snapshot exists
     
   - `get_snapshot($child_id, $track_id)`
     Returns full snapshot row
     
   - `get_snapshots_for_track($track_id)`
     Returns all snapshots for a track, keyed by child_id
     
   - `get_snapshots_for_classroom($classroom_id, $track_id)`
     Returns snapshots for children currently in a classroom, for a specific track
     Joins hl_child_track_snapshot with hl_child_classroom_current (status='active')
     
   - `ensure_snapshot($child_id, $track_id, $dob = null)`
     Creates a snapshot for a single child if one doesn't exist (used when teacher adds a child)
     If $dob not provided, reads from hl_child table

2. Update `HL_Assessment_Service::generate_child_assessment_instances()`:
   - Before generating instances, call `freeze_age_groups($track_id)` to ensure all children have snapshots
   - Instance instrument_id can now be NULL (resolved per-child at render time)

3. Register the new service in hl-core.php (require_once)

Commit: "feat: Phase 23.2 — child snapshot service + freeze age groups"
```

---

## 23.3 — Classroom Roster Management Service (use Opus)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.2 done.

PHASE 23.3: Service methods for teacher roster management (add/remove children).

1. Add methods to `HL_Classroom_Service`:

   a. `teacher_remove_child($classroom_id, $child_id, $enrollment_id, $reason, $note = '')`
      - Verify the enrollment has a teaching assignment for this classroom
      - UPDATE hl_child_classroom_current SET status='teacher_removed', removed_by_enrollment_id, removed_at=NOW(), removal_reason, removal_note
      - Write row to hl_child_classroom_history with reason
      - Audit log
      - Returns true/WP_Error
      
   b. `teacher_add_child($classroom_id, $enrollment_id, $data)`
      $data = ['first_name', 'last_name', 'dob', 'gender']
      - Verify enrollment has teaching assignment for this classroom
      - Get school_id from classroom
      - Duplicate detection: check hl_child for matching (first_name, last_name, dob, school_id)
        - If match found AND child already in this classroom (active): return error "already exists"
        - If match found AND child in another classroom or removed: reuse child record, create new classroom assignment
        - If no match: create new hl_child record
      - INSERT hl_child_classroom_current with status='active', added_by_enrollment_id, added_at=NOW()
      - Auto-create hl_child_track_snapshot via HL_Child_Snapshot_Service::ensure_snapshot()
      - Audit log
      - Returns child_id/WP_Error
      
   c. `get_children_in_classroom($classroom_id, $status = 'active')`
      - Update existing method to filter by status (default 'active' = backward compatible)
      - New signature: get_children_in_classroom($classroom_id, $include_removed = false)
      
   d. `get_removed_children_in_classroom($classroom_id)`
      - Returns children with status='teacher_removed', joined with enrollment for removed_by name

2. Update `get_children_in_classroom()` across ALL callers to ensure they only get active children by default. Search for all usages.

Commit: "feat: Phase 23.3 — teacher roster management service methods"
```

---

## 23.4 — Classroom Page Frontend (use Opus)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.3 done.

PHASE 23.4: Update Classroom Page for teacher add/remove children.

In class-hl-frontend-classroom-page.php:

1. Detect if the current user has a teaching assignment for this classroom:
   $is_assigned_teacher = check teaching assignments for user_id match

2. If assigned teacher, show:
   a. "Add Child" button at the top of children table
   b. "Remove" link/button on each child row (only for active children)

3. ADD CHILD FORM (shown via JS toggle or separate section):
   - Fields: First Name (required), Last Name (required), Date of Birth (required, date input), Gender (select: male/female/other/prefer not to say)
   - On submit: POST to same page, handle via template_redirect
   - POST handler: calls HL_Classroom_Service::teacher_add_child()
   - Success: redirect back to classroom page with success notice
   - Error: redirect back with error message
   - If ?return_to_assessment=INSTANCE_ID is in URL, show banner: "Child added successfully. [Return to your assessment →]"

4. REMOVE CHILD FLOW:
   - "Remove" button → JS opens a modal/confirmation section:
     - Reason dropdown: "No longer at this school", "Moved to another classroom", "Other"
     - Optional note textarea
     - Confirm / Cancel buttons
   - On confirm: POST with child_id, reason, note
   - POST handler: calls HL_Classroom_Service::teacher_remove_child()
   - Success: redirect back with notice "Child removed from classroom"

5. Access control: Add/Remove actions only available to teachers with active teaching assignment. Staff (manage_hl_core) can also add/remove.

6. Nonce protection on both add and remove POST handlers.

Commit: "feat: Phase 23.4 — classroom page teacher add/remove children"
```

---

## 23.5 — Instrument Renderer Rewrite (use Opus — LARGEST TASK)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.4 done.

PHASE 23.5: Rewrite HL_Instrument_Renderer for per-child age group sections.

This is a major rewrite. The renderer currently takes a single instrument and renders all children with that instrument's questions. The new renderer groups children by frozen_age_group and renders each group with its own instrument/question/behavior key.

Constructor changes:
- OLD: __construct($instrument, $children, $instance_id, $existing_answers, $instance)
- NEW: __construct($children_by_age_group, $instruments_by_age_group, $instance_id, $existing_answers, $instance)
  Where:
  - $children_by_age_group = ['infant' => [...], 'toddler' => [...], 'preschool' => [...]]
  - $instruments_by_age_group = ['infant' => $instrument_obj, 'toddler' => $instrument_obj, ...]

Alternative: keep existing constructor but add a new static factory method:
  HL_Instrument_Renderer::create_multi_age_group($classroom_id, $track_id, $instance_id, $existing_answers, $instance)
  This factory:
  1. Gets active children for classroom
  2. Gets frozen age groups from HL_Child_Snapshot_Service
  3. Groups children by age group
  4. Loads the correct instrument for each age group
  5. Returns a configured renderer instance

I recommend the factory approach — it keeps backward compat and centralizes the data assembly.

Render changes:
- render() method now loops over age groups present in the classroom
- For each age group section:
  - Section header: "Infant (3 children)", "Toddler (5 children)", etc.
  - Age-group-specific behavior key table (already exists per age band — reuse get_behavior_key_for_age_band())
  - Age-group-specific question prompt (from that age group's instrument)
  - Likert matrix with only the children in that age group
- All sections are in ONE <form> so draft/submit covers everything
- Hidden field per child: answers[{child_id}][_age_group] = 'infant' (so POST handler knows which instrument)

"Missing a child?" section at bottom of form:
- Link text: "Missing a child from your classroom?"
- Subtext: "You can add children to your classroom roster"
- Link: points to Classroom Page with ?return_to_assessment=INSTANCE_ID
- On click: JavaScript auto-saves draft via AJAX before navigating (POST form data to draft endpoint)
- If AJAX save fails, confirm with user: "Draft could not be saved. Continue anyway?"

Keep the existing render_submitted_summary() working — it should also group by age group for the read-only view.

Commit: "feat: Phase 23.5 — instrument renderer rewrite for per-child age groups"
```

---

## 23.6 — Child Assessment Frontend + Reconciliation (use Opus)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.5 done.

PHASE 23.6: Update child assessment frontend for roster reconciliation + submit logic.

In class-hl-frontend-child-assessment.php:

1. RENDER-TIME RECONCILIATION:
   When loading a draft or new assessment form:
   a. Get current active children in classroom (status='active' from hl_child_classroom_current)
   b. Get frozen age group snapshots for these children
   c. If any active child has no snapshot, auto-create via ensure_snapshot()
   d. Compare against existing draft childrows:
      - Children in roster but not in draft → add to form with blank answers
      - Children in draft but removed from roster (status='teacher_removed') → hide from form, keep childrow with status='not_in_classroom'
      - Children in draft AND in roster → show with pre-filled answers
   e. Pass reconciled data to HL_Instrument_Renderer factory

2. SUBMIT-TIME VALIDATION:
   When teacher submits:
   a. Re-check roster at submit time (someone may have changed it while form was open)
   b. For each submitted child answer:
      - If child is still active → save childrow with status='active', frozen_age_group, instrument_id
      - If child was removed since form load → save with status='stale_at_submit'
   c. For children in current roster with NO submitted answer:
      - If child was added AFTER the form was loaded (added_at > form load time) → save with status='not_yet_assessed'
      - Otherwise → this is a validation concern (teacher skipped a child)
   d. Allow submission even with missing answers (teacher may have valid reasons)

3. "NOT IN MY CLASSROOM" PER-CHILD OPTION:
   Add a checkbox or link per child row: "No longer in my classroom"
   When checked:
   - Radio buttons for that child become disabled
   - Hidden input sets that child's answer to null with status='skipped'
   - Optional: small dropdown for reason appears (left school / moved classroom)
   
   On submit: childrow saved with status='skipped', skip_reason from dropdown

4. DRAFT AJAX ENDPOINT:
   Register a wp_ajax action: wp_ajax_hl_save_assessment_draft
   Accepts: instance_id, answers (JSON), nonce
   Saves draft childrows without changing instance status
   Returns JSON success/error
   Used by the "Missing a child?" link to auto-save before navigating

5. Update render_assessment_form() to use the new HL_Instrument_Renderer factory.
   Update render_submitted_summary() to group by age group.

Commit: "feat: Phase 23.6 — child assessment frontend reconciliation + submit logic"
```

---

## 23.7 — Admin Updates (use Sonnet)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.6 done.

PHASE 23.7: Admin-side updates for roster management visibility.

1. Admin Classrooms page (class-hl-admin-classrooms.php):
   On classroom detail view, add a "Removed Children" section below the active children table:
   - Only shown if there are removed children
   - Collapsible section header: "Removed Children (N)"
   - Table columns: Name, DOB, Removed By (teacher name), Removed At (date), Reason, Note
   - No actions (admin can't un-remove from here in v1)

2. Admin Classrooms page:
   For active children, show "Added by" badge next to teacher-added children:
   - If added_by_enrollment_id is not null, show small badge: "Added by {name}"

3. Admin Assessments page (class-hl-admin-assessments.php):
   On child assessment detail view:
   - Show childrow status badges: 'skipped' (gray), 'stale_at_submit' (amber), 'not_in_classroom' (red)
   - Show frozen_age_group per child as a small badge next to name
   - Group child rows by age group in the detail view

4. Admin Reporting — child assessment CSV export:
   - Add frozen_age_group column
   - Add childrow status column
   - Add instrument_name column per child

Commit: "feat: Phase 23.7 — admin classroom + assessment UI updates"
```

---

## 23.8 — Seeders Update (use Sonnet)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.7 done.

PHASE 23.8: Update all seeders to populate hl_child_track_snapshot.

1. seed-demo:
   After creating children and teaching assignments, call:
   HL_Child_Snapshot_Service::freeze_age_groups($track_id)
   This auto-creates snapshots for all children based on their DOB.
   
   Verify: children in demo have DOBs that produce a mix of age groups.
   If all demo children happen to be the same age band, adjust some DOBs to create variety (at least 2 different age groups per classroom).

2. seed-palm-beach:
   Same — call freeze_age_groups() after children are created.
   Palm Beach has 286 children with real DOBs, should naturally produce a mix.

3. seed-lutheran:
   Same — call freeze_age_groups() after children are created.
   Lutheran has 286 children with DOBs.

4. For each seeder, update child assessment instance creation:
   - instrument_id can be NULL on the instance (resolved per-child)
   - If creating childrow entries in seeders, add frozen_age_group and instrument_id per child

5. Update the --clean commands to also DELETE FROM hl_child_track_snapshot for the track.

Commit: "feat: Phase 23.8 — seeders populate child track snapshots"
```

---

## 23.9 — Documentation (use Sonnet)

```
Read CLAUDE.md, README.md, docs/CHILD_ASSESSMENT_RESTRUCTURE.md. Phases 23.1-23.8 done.

PHASE 23.9: Documentation update for child assessment restructure.

Update these docs:
1. docs/02_DOMAIN_MODEL_ORG_STRUCTURE.md.md — Add hl_child_track_snapshot entity, update hl_child_classroom_current fields, update relationship diagram
2. docs/06_ASSESSMENTS_CHILDREN_TEACHER_OBSERVATION_COACHING.md.md — Major update: per-child age group assignment, frozen snapshots, roster management, reconciliation logic, form UX with age group sections
3. docs/01_GLOSSARY_CANONICAL_TERMS.md.md — Add "Frozen Age Group" and "Child Track Snapshot" definitions
4. docs/10_FRONTEND_PAGES_NAVIGATION_UX.md — Update Classroom Page (teacher add/remove), Child Assessment form UX

Update CLAUDE.md — mention per-child age groups, frozen snapshots, roster management in relevant sections.

Update README.md:
- Database schema: add hl_child_track_snapshot, update hl_child_classroom_current and hl_child_assessment_childrow descriptions
- Services: add HL_Child_Snapshot_Service description
- Frontend pages: update Classroom Page and Child Assessment descriptions
- Check off all Phase 23 items
- Update Key Design Decisions with frozen age group rationale

Commit: "docs: Phase 23.9 — child assessment restructure documentation"
```

---

## 23.10 — Staging Deploy + Test (use Sonnet)

```
Read README.md Phase 23. All code tasks (23.1-23.9) are complete.

Deploy and test on staging:

1. Deploy:
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging/wp-content/plugins/hl-core && git pull"

2. Nuke and seed-demo:
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core nuke --confirm='DELETE ALL DATA'"
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core seed-demo"

3. Verify hl_child_track_snapshot has rows:
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp db query 'SELECT frozen_age_group, COUNT(*) as cnt FROM wp_hl_child_track_snapshot GROUP BY frozen_age_group'"

4. Seed Lutheran:
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp hl-core seed-lutheran"

5. Verify Lutheran snapshots:
ssh -p 65002 u665917738@145.223.76.150 "cd /home/u665917738/domains/academy.housmanlearning.com/public_html/staging && wp db query 'SELECT frozen_age_group, COUNT(*) as cnt FROM wp_hl_child_track_snapshot WHERE track_id = (SELECT track_id FROM wp_hl_track WHERE track_code=\"LUTHERAN_CONTROL_2026\") GROUP BY frozen_age_group'"

Log all results in docs/PHASE23_VERIFICATION.md.
Check off 23.10 in README.md.

Commit: "verify: Phase 23.10 — staging deploy + snapshot verification"
Push: git push
```
