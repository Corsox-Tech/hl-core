# Beginnings Seeder Rebuild — Design Spec

**Date:** 2026-03-26
**Goal:** Replace existing Beginnings seeders with a single CLI command that creates Cycle 1 (closed, with ~70% full completion) and Cycle 2 (active, fresh, ready for demo) with proper child assessment instruments and instances.

---

## 1. CLI Command

**Single file:** `class-hl-cli-seed-beginnings.php` (rewrite)
**Delete:** `class-hl-cli-seed-beginnings-y2.php` (no longer needed)

```
wp hl-core seed-beginnings          # Seeds everything (Cycle 1 + Cycle 2)
wp hl-core seed-beginnings --clean  # Nukes all Beginnings data (both cycles)
```

The `--clean` command deletes both cycles and all related data (enrollments, components, teams, children, classrooms, users, coach assignments, child assessment instances/childrows, etc.), then returns. A fresh `seed-beginnings` after clean creates the full dataset.

Constants:
- `PARTNERSHIP_CODE = 'BEGINNINGS-2025'`
- `CYCLE_1_CODE = 'BEGINNINGS-Y1-2025'`
- `CYCLE_2_CODE = 'BEGINNINGS-Y2-2026'`
- `META_KEY = '_hl_beginnings_seed'`

---

## 2. Org Structure

Shared across both cycles (created once):

- **Partnership:** Beginnings School - 2025-2026, code `BEGINNINGS-2025`
- **District:** Beginnings School District
- **4 Schools:** Boston, Florida, Texas, Colombia

### Classrooms (2 per school, 8 total)

| School   | Classroom 1              | Age Band   | Classroom 2               | Age Band   |
|----------|--------------------------|------------|---------------------------|------------|
| Boston   | Boston - Infant Room     | infant     | Boston - Toddler Room     | toddler    |
| Florida  | Florida - Preschool Room | preschool  | Florida - Pre-K Room      | pre_k      |
| Texas    | Texas - Toddler Room     | toddler    | Texas - Preschool Room    | preschool  |
| Colombia | Colombia - Infant Room   | infant     | Colombia - Preschool Room | preschool  |

Texas gets a second classroom (Preschool Room) added vs. the old seeder.

### Children (5 per classroom, 40 total)

Deterministic names and DOBs. 10 first names and 10 last names in fixed arrays; child `i` in classroom `c` picks `first_names[(c * 5 + i) % 10]` and `last_names[(c * 5 + i + 3) % 10]`. DOB computed from age band midpoint minus a fixed offset per child index (no `wp_rand`).

---

## 3. Users (36 WP accounts total)

All users created as `subscriber` role, password = email.

### School Leaders (4)

| School   | Email                                | Display Name            |
|----------|--------------------------------------|-------------------------|
| Boston   | boston-school-leader@yopmail.com      | Beth Boston-Leader      |
| Florida  | florida-school-leader@yopmail.com    | Fiona Florida-Leader    |
| Texas    | texas-school-leader@yopmail.com      | Tina Texas-Leader       |
| Colombia | colombia-school-leader@yopmail.com   | Carmen Colombia-Leader  |

### District Leader (1)

| Email                                    | Display Name                  |
|------------------------------------------|-------------------------------|
| district-lead-beginnings@yopmail.com     | Diana District-Lead-Beginnings |

### Teams (6 teams, 1 mentor + 4 teachers each = 30)

| Team         | School   | Mentor Email                       | Teachers (4 each)                                                |
|--------------|----------|------------------------------------|------------------------------------------------------------------|
| T01-Boston   | Boston   | mentor-T_01-boston@yopmail.com      | john_, mary_, steve_, lisa_ @yopmail.com                         |
| T02-Boston   | Boston   | mentor-T_02-boston@yopmail.com      | carlos_, ana_, mike_, sarah_ @yopmail.com                        |
| T01-Florida  | Florida  | mentor-T_01-florida@yopmail.com    | david_, rachel_, james_, emma_ @yopmail.com                      |
| T02-Florida  | Florida  | mentor-T_02-florida@yopmail.com    | tom_, nina_, leo_, grace_ @yopmail.com                           |
| T01-Texas    | Texas    | mentor-T_01-texas@yopmail.com      | ryan_, mia_, jake_, lily_ @yopmail.com                           |
| T01-Colombia | Colombia | mentor-T_01-colombia@yopmail.com   | ben_, chloe_, zoe_, diego_ @yopmail.com                          |

**New vs. old seeder:** T02-Florida gains Grace, T01-Colombia gains Diego (both teams now have 4 teachers).

### Additional User Created Only for Cycle 2 (1)

| Email                              | Display Name          | Role in C2       |
|------------------------------------|-----------------------|------------------|
| new-hire-mentor-boston@yopmail.com  | Natalia NewHire-Mentor | Mentor (Phase 1) |

### Teaching Assignments

4 teachers per school, 2 classrooms per school => 2 teachers per classroom.
Within each team, teachers are distributed round-robin across their school's classrooms. First assigned teacher in each classroom is lead teacher.

Mentors are NOT assigned to classrooms (they don't have direct teaching assignments in this program).

---

## 4. Cycle 1 — Closed

**Cycle record:**
- Name: `Beginnings - Cycle 1 (2025)`
- Code: `BEGINNINGS-Y1-2025`
- Status: `closed`
- Dates: 2025-01-15 to 2025-09-30
- cycle_type: `program`

### Pathways (3)

1. **B2E Teacher Phase 1** (17 components) — target: teacher
2. **B2E Mentor Phase 1** (19 components) — target: mentor
3. **B2E Streamlined Phase 1** (11 components) — target: school_leader

Component types and structure identical to current seeder (courses TC0-TC4, MC1-MC2, self_reflections, RP sessions, coaching sessions, child assessments, TSAs).

### Enrollments (35 total)

All Cycle 1 users enrolled. Pathway assignments:
- 24 teachers -> Teacher Phase 1
- 6 mentors -> Mentor Phase 1
- 4 school leaders + 1 district leader -> Streamlined Phase 1

### Completion (~70% fully complete)

**Fully completed (~25 of 35):** All components `complete` with dates spread Jan-Sep 2025. Completion rollup at 100%.

**Partial (~10 of 35, the "stragglers"):**
- 7 teachers: Lisa (T01-Boston), Sarah (T02-Boston), Emma (T01-Florida), Leo (T02-Florida), Grace (T02-Florida), Lily (T01-Texas), Diego (T01-Colombia)
- 2 mentors: Maria (Colombia), Miguel (T02-Florida)
- 1 school leader: Tina (Texas)

Stragglers complete 50-70% of their components (deterministic cutoff, not random).

**Note:** The people who leave after Cycle 1 (Lisa-promoted, Emma, Leo, Lily, Maria) are all stragglers. This is intentional — they didn't finish and moved on.

---

## 5. Cycle 2 — Active

**Cycle record:**
- Name: `Beginnings - Cycle 2 (2026)`
- Code: `BEGINNINGS-Y2-2026`
- Status: `active`
- Dates: 2026-01-15 to 2026-09-30
- cycle_type: `program`

### Roster Changes (who stays, who leaves, who's new)

**Leave (not enrolled in Cycle 2):**
- Maria (Mentor, Colombia) — left organization
- Lisa (Teacher, T01-Boston) — promoted to mentor (re-enrolled as mentor)
- Leo (Teacher, T02-Florida) — left
- Emma (Teacher, T01-Florida) — left
- Lily (Teacher, T01-Texas) — left

**New enrollments:**
- Lisa — re-enrolled as mentor for Colombia team, Mentor Transition pathway
- Natalia NewHire-Mentor — enrolled as mentor at Boston, Mentor Phase 1 pathway

### Pathways (8, matching ELCPB Y2 V2 structure)

1. **B2E Teacher Phase 1** (17 cmp) — for new teachers (not assigned this cycle, but created)
2. **B2E Teacher Phase 2** (16 cmp) — returning teachers
3. **B2E Mentor Phase 1** (19 cmp) — Natalia
4. **B2E Mentor Phase 2** (18 cmp) — 5 returning mentors
5. **B2E Mentor Transition** (18 cmp) — Lisa (teacher->mentor promotion)
6. **B2E Mentor Completion** (4 cmp) — created but not assigned this cycle
7. **B2E Streamlined Phase 1** (11 cmp) — created but not assigned this cycle
8. **B2E Streamlined Phase 2** (10 cmp) — school leaders + district leader

### Enrollments (32 total)

| Group                    | Count | Pathway           |
|--------------------------|-------|-------------------|
| Returning teachers       | 20    | Teacher Phase 2   |
| Lisa (promoted)          | 1     | Mentor Transition |
| 5 returning mentors      | 5     | Mentor Phase 2    |
| Natalia (new mentor)     | 1     | Mentor Phase 1    |
| 4 school leaders         | 4     | Streamlined Phase 2 |
| 1 district leader        | 1     | Streamlined Phase 2 (note: target_roles includes district_leader) |

(Note: Streamlined Phase 2 target_roles = `['school_leader', 'district_leader']` or just `['school_leader']` with district_leader assigned manually, matching current pattern.)

### Teams in Cycle 2

Same 6 teams recreated for Cycle 2:
- T01-Boston: Marco (mentor) + John, Mary, Steve (3 teachers — Lisa left)
- T02-Boston: Monica (mentor) + Carlos, Ana, Mike, Sarah (4 teachers)
- T01-Florida: Marta (mentor) + David, Rachel, James (3 teachers — Emma left)
- T02-Florida: Miguel (mentor) + Tom, Nina, Grace (3 teachers — Leo left)
- T01-Texas: Manuel (mentor) + Ryan, Mia, Jake (3 teachers — Lily left)
- T01-Colombia: Lisa (new mentor) + Ben, Chloe, Zoe, Diego (4 teachers)

Natalia is enrolled as a team member (not mentor role) on T01-Boston — she shadows Marco as part of her Mentor Phase 1 learning. She appears on the team roster but Marco remains the team's mentor.

### Teaching Assignments in Cycle 2

Same distribution logic: teachers round-robin across their school's 2 classrooms. Same classrooms, same children (children persist across cycles). Teams with 3 teachers: classroom 1 gets 2 teachers, classroom 2 gets 1 teacher.

### Component States

All components in `not_started` state — no progress on Cycle 2 yet.

---

## 6. Child Assessment Instruments

**4 instruments created** (shared across both cycles):

| Instrument Name              | Type              | Age Band   |
|------------------------------|-------------------|------------|
| Beginnings Infant Assessment | children_infant   | infant     |
| Beginnings Toddler Assessment | children_toddler | toddler    |
| Beginnings Preschool Assessment | children_preschool | preschool |
| Beginnings Pre-K Assessment  | children_pre_k    | pre_k      |

Each instrument has 1 Likert question (same structure as ELCPB): key=`q1`, scale 1-5.

### Teacher Assessment & Form Instruments

The 6 teacher assessment instruments (coaching_rp_notes, mentoring_rp_notes, coaching_action_plan, mentoring_action_plan, classroom_visit_form, self_reflection_form) are assumed to already exist from the ELCPB Y2 V2 setup. The seeder will NOT create these — it reuses whatever instruments are in `hl_teacher_assessment_instrument`. If they don't exist, the form components will still render but won't have instrument data. This matches the ELCPB pattern where instruments are created once and shared.

---

## 7. Child Assessment Instances

### Cycle 1 (historical, submitted)

For every teacher with a teaching assignment in Cycle 1:
- Create 1 Pre instance + 1 Post instance per classroom they're assigned to
- Status: `submitted`
- Childrows: one per child in classroom, with randomized scores (1-5)
- Dates: Pre in Feb 2025, Post in Aug 2025

For mentors: No child assessment instances (mentors don't have teaching assignments in Beginnings).

### Cycle 2 (pending, ready for demo)

For every teacher with a teaching assignment in Cycle 2:
- Create 1 Pre instance per classroom they're assigned to
- Status: `pending`
- Childrows: one per child in classroom, `answers_json = NULL` (no scores yet)
- instrument_id resolved from classroom's age_band

**Result:** When a teacher logs in and views their Child Assessment (Pre) component, they see their children listed and ready to score.

---

## 8. Coach Assignment

**Coach user:** `mgonzalez@housmanlearning.com` (found or created)
**Scope:** `school` — assigned to Beginnings Boston (school_id)
**Cycle:** Cycle 2 only

This gives the coach visibility over all mentors and teachers at Boston in the coach dashboard.

---

## 9. Demo Accounts (printed at end of seeder)

| Role          | Name                      | Email                                  |
|---------------|---------------------------|----------------------------------------|
| Coach         | Mateo Gonzalez            | mgonzalez@housmanlearning.com          |
| School Leader | Beth Boston-Leader        | boston-school-leader@yopmail.com        |
| Mentor        | Marco Mentor T01-Boston   | mentor-T_01-boston@yopmail.com          |
| Teacher       | John Teacher T01-Boston   | john_teacher-T_01-boston@yopmail.com    |

Password for yopmail accounts = email address.

---

## 10. Clean Command

`--clean` deletes data for BOTH cycles:
1. Find both cycle IDs by code
2. For each cycle (order matters — child tables before parent tables):
   - `hl_completion_rollup` (by enrollment_id)
   - `hl_component_state` (by enrollment_id)
   - `hl_component_override` (by enrollment_id)
   - `hl_team_membership` (by enrollment_id AND by team_id)
   - `hl_teaching_assignment` (by enrollment_id)
   - `hl_child_assessment_childrow` (by instance_id from hl_child_assessment_instance)
   - `hl_child_assessment_instance` (by enrollment_id)
   - `hl_teacher_assessment_instance` (by enrollment_id)
   - `hl_observation_attachment` (by observation_id from hl_observation)
   - `hl_observation_response` (by observation_id from hl_observation)
   - `hl_observation` (by cycle_id)
   - `hl_coaching_session_submission` (by session_id from hl_coaching_session)
   - `hl_coaching_session_observation` (by session_id from hl_coaching_session)
   - `hl_coaching_attachment` (by session_id from hl_coaching_session)
   - `hl_coaching_session` (by cycle_id)
   - `hl_rp_session_submission` (by session_id from hl_rp_session)
   - `hl_rp_session` (by cycle_id)
   - `hl_classroom_visit_submission` (by visit_id from hl_classroom_visit)
   - `hl_classroom_visit` (by cycle_id)
   - `hl_component_prereq_item` (by group_id from hl_component_prereq_group)
   - `hl_component_prereq_group` (by component_id)
   - `hl_component_drip_rule` (by component_id)
   - `hl_component` (by cycle_id)
   - `hl_pathway` (by cycle_id)
   - `hl_team` (by cycle_id)
   - `hl_enrollment` (by cycle_id)
   - `hl_cycle_school` (by cycle_id)
   - `hl_coach_assignment` (by cycle_id)
   - `hl_child_cycle_snapshot` (by cycle_id)
   - `hl_cycle` (by cycle_id)
3. Delete children + classrooms by school (Beginnings% schools):
   - `hl_child_classroom_current`, `hl_child_classroom_history` (by classroom_id)
   - `hl_child` (by school_id)
   - `hl_classroom` (by school_id)
4. Delete WP users tagged with META_KEY
5. Delete orgunits (Beginnings% schools + district)
6. Delete partnership
7. Delete instruments (Beginnings% names)

---

## 11. Files Changed

- **Rewrite:** `includes/cli/class-hl-cli-seed-beginnings.php`
- **Delete:** `includes/cli/class-hl-cli-seed-beginnings-y2.php`
- **Update:** `hl-core.php` (remove Y2 class registration if present)
