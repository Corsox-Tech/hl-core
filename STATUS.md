# STATUS.md — HL Core Build Status

**Phases 1-32 + 35 complete. Deployed to production (March 2026).** 28 shortcode pages, 15+ admin pages, 39 DB tables, 15+ services. Lutheran control group provisioned (39 enrollments, 286 children, 11 schools).

---

## Build Queue (Ordered — work top to bottom)

Pick up from the first unchecked `[ ]` item each session.

### ELCPB Data & Operations (Active — March 2026)
- [x] **Fix admin menu duplicate** — Rename auto-generated "HL Core" submenu to "Cycles", remove duplicate entry.
- [x] **Link ELCPB Partnership ↔ Cycle** — Set `partnership_id` on Year 1 Cycle to point to ELCPB Partnership.
- [~] **Import ELCPB Year 1 remaining data** — TSA complete (52 pre, 40 post). Child assessments imported from WPForms: 261 children, 27 teaching assignments, 45 instances, 494 childrows. 4 Life Span teachers (311/315/317/321) have no enrollment — child assessment entries skipped. Life Span VPK classroom has no form.
- [x] **Create ELCPB Year 2 Cycle + Pathways** — Cycle `ELCPB-Y2-2026` (id=5) linked to Partnership 4, 2026-03-30 to 2026-09-12. 8 pathways: Teacher Phase 1 (15 cmp), Teacher Phase 2 (14), Mentor Phase 1 (9), Mentor Phase 2 (16), Mentor Transition (16), Mentor Completion (2), Streamlined Phase 1 (9), Streamlined Phase 2 (8). CLI: `wp hl-core setup-elcpb-y2`.
- [x] **ECSELent Adventures setup** — Partnership EA-2025, Cycle EA-TRAINING-2025, 2 pathways (Preschool/Pre-K + K-2), 3 shared training course components, enrollment from LD group, pathway assignment by materials group, completion import. CLI: `wp hl-core setup-ea`.
- [x] **Short courses migration** — 3 course-type Cycles (SC-EEW, SC-RP, SC-MMST), each with 1 pathway + 1 component, enrollment discovery from LD activity, completion import. CLI: `wp hl-core setup-short-courses`.
- [x] **Frontend: Resource card for syllabus_url** — Enhanced rendering on Program Page: styled card with accent border instead of plain link. Generic for any pathway with syllabus_url.

### Cross-Pathway Events, Forms & Coaching (Active — March 2026)
- [x] **DB: 5 new tables** — `hl_rp_session`, `hl_rp_session_submission`, `hl_classroom_visit`, `hl_classroom_visit_submission`, `hl_coaching_session_submission`. ENUM migration adds 3 component types.
- [x] **3 new services** — `HL_RP_Session_Service`, `HL_Classroom_Visit_Service`, `HL_Session_Prep_Service` + coaching service enhancements for form submissions.
- [x] **5 new frontend renderers** — RP Notes, Action Plan, Self-Reflection, Classroom Visit, RP Session page controller with role-based views.
- [x] **Component Page dispatchers** — 3 new component type branches (`self_reflection`, `reflective_practice_session`, `classroom_visit`).
- [x] **Coaching Hub enhancements** — Coaches tab (admin), coaches grid + calendar view (frontend), calendar widget on My Coaching.
- [x] **Cycle Editor subtabs** — RP Sessions + Classroom Visits subtabs in Coaching tab.
- [x] **6 instruments seeded** — coaching_rp_notes, mentoring_rp_notes, coaching_action_plan, mentoring_action_plan, classroom_visit_form, self_reflection_form.
- [x] **ELCPB Y2 CLI rebuild** — `setup-elcpb-y2-v2` with correct 8 pathways, new component types, prerequisites, demo coach (Lauren Orf).
- [~] **Integration testing** — Pending Session 5 deployment and verification.

### Phase 33: Individual Enrollments (B2E Master Reference)
- [ ] **33.1 — DB: `hl_individual_enrollment` table** — Create table with user_id, course_id, enrolled_at, expires_at, status, enrolled_by, notes.
- [ ] **33.2 — Individual Enrollment Service** — CRUD, expiration checks, LearnDash progress queries.
- [ ] **33.3 — Admin: Individual Enrollments pages** — Course List page + Course Detail page under HL Core menu.
- [ ] **33.4 — Frontend: My Courses on Dashboard** — Add "My Courses" section to `[hl_dashboard]` for individual enrollments.
- [ ] **33.5 — Expiration enforcement** — Check on course access, auto-mark expired, optional LearnDash unenroll.

### Phase 34: Program Progress Matrix Report (B2E Master Reference)
- [ ] **34.1 — Report query** — Query all LearnDash Course activities across all Cycles, map completion per participant.
- [ ] **34.2 — Admin report view** — Course-by-course grid with Cycle/School/Team/Role filters.
- [ ] **34.3 — CSV export** — Export the matrix as CSV.

### Lower Priority (Future)
- [ ] Scope-based user creation for client leaders
- [ ] Import templates (downloadable CSV)
- [ ] MS365 Calendar Integration (requires Azure AD infrastructure)
- [ ] BuddyBoss Profile Tab (out of scope for v1)
- [ ] Frontend CSS redesign (modernize all 25+ shortcode pages)

---

## Completed Phases (1-32 + 35)

Phases 1-11: Foundation (DB schema, LearnDash wiring, assessments, coaching, reporting, frontend, sidebar nav)
Phases 14-18: Admin UX, architecture (pathway assignments, cohort groups, hierarchy nav), CSS design system
Phases 19-21: Custom teacher self-assessment system, control group support, Lutheran seeder, nuke command
Phase 22: Grand Rename (Center→School, Children→Child, Cohort→Track hierarchy restructure)
Phases 23-27: Child assessment restructure (per-child age groups, roster management), teacher assessment editor, CSV exports, separate PRE/POST instruments
Phases 28-31: Dashboard shortcode, instrument nuke protection, admin docs system, K-2nd grade age group
Phase 32: Phase entity + Track types architecture (B2E Master Reference)
Phase 35: Admin UX/UI redesign + menu consolidation
Grand Rename V3: Corrective rename — Partnership↔Cohort swap, Phase entity deleted. Partnership=container, Cycle=yearly run.

Note: Phases 12 (MS365 Calendar) and 13 (BuddyBoss Profile Tab) were deferred.
