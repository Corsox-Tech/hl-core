# STATUS.md — HL Core Build Status

**Phases 1-32 + 35 complete. Deployed to production (March 2026).** 28 shortcode pages, 15+ admin pages, 39 DB tables, 15+ services. Lutheran control group provisioned (39 enrollments, 286 children, 11 schools).

---

## Build Queue (Ordered — work top to bottom)

Pick up from the first unchecked `[ ]` item each session.

### Phase 33: Individual Enrollments (B2E Master Reference)
- [ ] **33.1 — DB: `hl_individual_enrollment` table** — Create table with user_id, course_id, enrolled_at, expires_at, status, enrolled_by, notes.
- [ ] **33.2 — Individual Enrollment Service** — CRUD, expiration checks, LearnDash progress queries.
- [ ] **33.3 — Admin: Individual Enrollments pages** — Course List page + Course Detail page under HL Core menu.
- [ ] **33.4 — Frontend: My Courses on Dashboard** — Add "My Courses" section to `[hl_dashboard]` for individual enrollments.
- [ ] **33.5 — Expiration enforcement** — Check on course access, auto-mark expired, optional LearnDash unenroll.

### Phase 34: Program Progress Matrix Report (B2E Master Reference)
- [ ] **34.1 — Report query** — Query all LearnDash Course activities across all Phases of a Track, map completion per participant.
- [ ] **34.2 — Admin report view** — Course-by-course grid with Phase/School/Team/Role filters.
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

Note: Phases 12 (MS365 Calendar) and 13 (BuddyBoss Profile Tab) were deferred.
