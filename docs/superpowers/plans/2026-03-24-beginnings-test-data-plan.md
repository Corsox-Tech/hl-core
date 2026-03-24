# Beginnings Test Data Seeder — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a WP-CLI seeder that provisions a complete "Beginnings School" test dataset with realistic Cycle 1 completion data and a Cycle 2 CSV roster for import testing.

**Architecture:** Single CLI class `HL_CLI_Seed_Beginnings` following the established `seed-demo` pattern. Creates org structure, users, cycle, pathways (Y2-style component types), enrollments, teams, classrooms, children, component states, then generates a Cycle 2 CSV roster. All data tagged with meta key for clean removal.

**Tech Stack:** PHP 7.4+, WordPress WP-CLI, existing HL Core services/repositories.

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `includes/cli/class-hl-cli-seed-beginnings.php` | Create | Main seeder — all logic in one file |
| `hl-core.php` | Modify (lines ~189-201, ~261-273) | Add require + register |
| `data/beginnings-cycle-2-roster.csv` | Generated at runtime | Cycle 2 import roster (gitignored) |

---

## Task 1: Create the CLI class skeleton + registration

**Files:**
- Create: `includes/cli/class-hl-cli-seed-beginnings.php`
- Modify: `hl-core.php`

- [ ] **Step 1:** Create `class-hl-cli-seed-beginnings.php` with class skeleton, constants, `register()`, `run()`, `clean()`, `demo_exists()` methods. Follow the `seed-demo` pattern exactly.
- [ ] **Step 2:** Add `require_once` and `register()` call in `hl-core.php` within the WP_CLI block.
- [ ] **Step 3:** Commit skeleton.

**Constants:**
- `CYCLE_CODE = 'BEGINNINGS-Y1-2025'`
- `PARTNERSHIP_CODE = 'BEGINNINGS-2025'`
- `META_KEY = '_hl_beginnings_seed'`

---

## Task 2: Org structure — Partnership, District, Schools

- [ ] **Step 1:** `seed_orgunits()` — Create 1 partnership, 1 district, 4 schools (Boston, Florida, Texas, Colombia). District parent of all schools.
- [ ] **Step 2:** District leader WP user: `district-lead-beginnings@yopmail.com`, display name "Diana District-Lead Beginnings".
- [ ] **Step 3:** School leader WP users (4): `boston-school-leader@yopmail.com` etc., display names like "Beth Boston-Leader".
- [ ] **Step 4:** `seed_cycle()` — Create Cycle with `cycle_type=program`, linked to all 4 schools.

---

## Task 3: Teams, Mentors, Teachers

**6 teams:**
- Boston: Team 01, Team 02 (4+4 teachers)
- Florida: Team 01, Team 02 (4+3 teachers)
- Texas: Team 01 (4 teachers)
- Colombia: Team 01 (3 teachers)

**6 mentors** (1 per team): `mentor-T_01-boston@yopmail.com` → "Marco Mentor T01-Boston"

**22 teachers** with obvious names: `john_teacher-T_01-boston@yopmail.com` → "John Teacher T01-Boston"

Teacher first names pool: John, Mary, Steve, Lisa, Carlos, Ana, Mike, Sarah, David, Rachel, James, Emma, Tom, Nina, Leo, Zoe, Ryan, Mia, Jake, Lily, Ben, Chloe

- [ ] **Step 1:** `seed_users()` — Create all WP users (password = email). Tag with meta key.
- [ ] **Step 2:** `seed_enrollments()` — Enroll everyone in the cycle with correct roles and school/district IDs.
- [ ] **Step 3:** `seed_teams()` — Create 6 teams, add mentor + teachers as members.

---

## Task 4: Pathways with Y2 component types

Replicate the 3 Phase 1 pathways from the Y2 V2 setup:

| Pathway | Components | Target |
|---------|-----------|--------|
| Teacher Phase 1 | 17: TSA Pre, CA Pre, TC0-TC4, 4x self_reflection, 4x reflective_practice_session, CA Post, TSA Post | teacher |
| Mentor Phase 1 | 19: TSA Pre, CA Pre, TC0-TC4, MC1-MC2, 4x coaching_session_attendance, 4x reflective_practice_session, CA Post, TSA Post | mentor |
| Streamlined Phase 1 | 11: TC0, TC1-TC4(S), MC1-MC2(S), 4x classroom_visit | school_leader, district_leader |

Use real LD course IDs (TC0=31037, TC1=30280, etc.).

- [ ] **Step 1:** `seed_pathways()` — Create 3 pathways with components and prerequisites, using the exact same `cmp()` helper pattern from Y2 V2.
- [ ] **Step 2:** `assign_pathways()` — Set `assigned_pathway_id` on each enrollment. Teachers → Teacher P1, Mentors → Mentor P1, School Leaders + District Leader → Streamlined P1.

---

## Task 5: Classrooms, Teaching Assignments, Children

**Classrooms (7 total):**
- Boston: Infant Room, Toddler Room (2)
- Florida: Preschool Room, Pre-K Room (2)
- Texas: Toddler Room (1)
- Colombia: Infant Room, Preschool Room (2)

- [ ] **Step 1:** `seed_classrooms()` — Create 7 classrooms with age bands.
- [ ] **Step 2:** `seed_teaching_assignments()` — Assign each teacher to 1 classroom. Distribute evenly.
- [ ] **Step 3:** `seed_children()` — 3-8 children per classroom with random names, DOBs, gender. Assign to classrooms.
- [ ] **Step 4:** Freeze child age group snapshots for the cycle.

---

## Task 6: Component states — 90% completion

- [ ] **Step 1:** `seed_component_states()` — For each enrollment, insert `component_state` rows.
  - **~90% fully complete:** All components get `status=completed`, `completed_at` with staggered dates.
  - **2 teachers mid-pathway:** Stop at component ~60% (mark remaining as `available` or omit).
  - **1 mentor mid-pathway:** Stop at ~70%.
  - **1 school leader mid-pathway:** Stop at ~50%.
- [ ] **Step 2:** `seed_rollups()` — Insert `completion_rollup` for fully completed enrollments.

---

## Task 7: Generate Cycle 2 CSV roster

- [ ] **Step 1:** `generate_cycle2_csv()` — Build roster with ~75% retention:
  - All 5 leaders (district + 4 school) return unchanged
  - 5 of 6 mentors return (drop 1 — e.g., Colombia mentor "left")
  - 1 Cycle 1 teacher promoted to mentor (pick a fully-completed teacher from Boston)
  - 1 brand new mentor hire (not in Cycle 1): `new-hire-mentor-colombia@yopmail.com`
  - ~16-17 of 22 teachers return (drop 5-6 randomly, favoring the incomplete ones)
  - Some teachers may move teams between cycles (realistic churn)
- [ ] **Step 2:** CSV columns: `first_name,last_name,email,role,school,team,pathway`
  - `pathway` values: `Teacher Phase 2`, `Mentor Phase 2`, `Mentor Transition`, `Streamlined Phase 2`, `Mentor Completion`
  - Returning teachers → Phase 2 pathways
  - Promoted teacher (now mentor) → Mentor Transition
  - Returning mentors who completed Phase 1 → Mentor Phase 2 or Mentor Completion
  - New hire mentor → Mentor Phase 1
  - School Leaders/District → Streamlined Phase 2
- [ ] **Step 3:** Write to `data/beginnings-cycle-2-roster.csv`. Print path in CLI output.

---

## Task 8: Clean command + final wiring

- [ ] **Step 1:** `clean()` — Delete in reverse dependency order: component_states, rollups, team_memberships, teaching_assignments, child data, components, pathways, teams, enrollments, cycle_school, cycle, partnership, orgunits. Delete created WP users (tagged 'created'), untag found users.
- [ ] **Step 2:** Final summary output matching demo seeder style.
- [ ] **Step 3:** Deploy to test, run `wp hl-core seed-beginnings`, verify.
- [ ] **Step 4:** Commit everything.
