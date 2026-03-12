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

## Completed Phases

- **Phase 1:** JetFormBuilder Integration Foundation
- **Phase 2:** LearnDash Completion Wiring
- **Phase 3:** Child Assessment (Custom Form)
- **Phase 4:** Observation & Coaching Workflows
- **Phase 5:** Reporting Dashboard
- **Phase 6:** Constraints & Polish
- **Phase 7:** Front-End — Participant Experience
- **Phase 8:** Front-End — Leader Experience
- **Phase 9:** Front-End — Staff/Admin CRM Directory
- **Phase 10:** Coach Assignment + Coaching Enhancement
- **Phase 11:** Sidebar Navigation & Listing Pages
- **Phase 12:** MS365 Calendar Integration (deferred — requires Azure AD)
- **Phase 13:** BuddyBoss Profile Tab (deferred — out of scope for v1)
- **Phase 14:** Admin UX Improvements
- **Phase 15:** Architecture — Explicit Pathway Assignments + Cohort Groups
- **Phase 16:** Cohort Editor — Inline Sub-Entity CRUD
- **Phase 17:** Admin UX — Hierarchy & Navigation
- **Phase 18:** Frontend CSS Design System
- **Phase 19:** Custom Teacher Self-Assessment System
- **Phase 20:** Control Group Support
- **Phase 21:** Assessment System Overhaul + Lutheran Seeder + Nuke Command
- **Phase 22:** Grand Rename — Center→School, Children→Child, Cohort→Track
- **Phase 23:** Child Assessment Restructure — Per-Child Age Groups + Roster Management
- **Phase 24:** Teacher Assessment — Admin Visual Editor + Frontend Design Upgrade
- **Phase 25:** Customizable Child Assessment Instructions & Behavior Key
- **Phase 26:** Assessment CSV Export — Response Data Exports
- **Phase 27:** Separate PRE/POST Teacher Assessment Instruments
- **Phase 28:** Dashboard Shortcode (`[hl_dashboard]`)
- **Phase 29:** Protect Instruments from Nuke + Admin-Customizable Display Styles
- **Phase 30:** Admin Documentation System
- **Phase 31:** K-2nd Grade Age Group + JFB Cleanup + Instrument Preview
- **Phase 32:** Phase Entity + Track Types (Architecture — B2E Master Reference)
- **Phase 35:** Admin UX/UI Redesign + Menu Consolidation
