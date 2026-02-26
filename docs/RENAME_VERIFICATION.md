# Grand Rename — Verification Results

## V1: Grep Verification (2026-02-25)

### Stale Pattern Checks (includes/ PHP files, excluding installer migrations)

| Pattern | Result | Status |
|---------|--------|--------|
| `center_id` | 0 hits | PASS |
| `children_assessment` | 0 hits | PASS |
| `cohort_group` | 0 hits | PASS |
| `hl_cohort_center` | 0 hits | PASS |
| `hl_cohort_school` (old table name) | 0 hits | PASS |

### `hl_cohort` references in code (container entity — expected)

All remaining `hl_cohort` references correctly refer to the **container entity** (`hl_cohort` table = Cohort = contract/container):

- `class-hl-admin-cohorts.php` — CRUD for Cohorts (containers)
- `class-hl-admin-reporting.php` — Cohort filter dropdown
- `class-hl-admin-tracks.php` — Cohort parent selector for tracks
- `class-hl-cli-seed-lutheran.php` — Creating Cohort (container) records
- `class-hl-cli-seed-palm-beach.php` — Creating Cohort (container) records
- `class-hl-frontend-tracks-listing.php` — Cohort filter on tracks listing
- `class-hl-cohort-service.php` — Cohort (container) service
- `class-hl-date-utils.php` — Timezone lookup from Cohort

### Documentation Checks

All 11 spec docs (00-10) + CLAUDE.md updated for Track/Cohort hierarchy. Only references to old naming appear in:
- `docs/RENAME_PLAN.md` — intentional (documents old→new mapping)
- `docs/RENAME_PROMPTS.md` — intentional (documents rename prompts)

### Verdict: PASS

---

## V2: Staging seed-demo Test (2026-02-25)

1. `wp hl-core nuke --confirm='DELETE ALL DATA'` — SUCCESS (36 tables truncated, 1774 rows deleted)
2. `wp hl-core seed-demo` — SUCCESS (17/17 steps completed)
   - Track created: id=1, code=DEMO-2026
   - 1 district, 2 schools, 4 classrooms, 4 instruments
   - 15 enrollments, 2 teams, 10 teaching assignments, 26 children
   - 2 pathways (7 activities total), prereq rules (ALL_OF, ANY_OF, N_OF_M), drip rules
   - 12 activity states, 15 completion rollups
   - 3 coach assignments, 6 coaching sessions
3. DB verification: `wp_hl_track` contains `track_id=1, track_code=DEMO-2026, track_name='Demo Track 2026'`

### Verdict: PASS

## V3: Staging seed-lutheran Test (2026-02-25)

1. `wp hl-core nuke --confirm='DELETE ALL DATA'` — SUCCESS
2. `wp hl-core seed-lutheran` — SUCCESS (14/14 steps completed)
   - District: 1 (code: LSF_PALM_BEACH)
   - Schools: 11 (with alias resolution for 11 Excel name variants)
   - Track: id=1, code=LUTHERAN_CONTROL_2026, is_control_group=1
   - Cohort (container): id=1, code=B2E_LSF, name="B2E Mastery - Lutheran Services Florida"
   - Track.cohort_id = 1 (correctly linked to container)
   - 29 classrooms, 47 teachers, 286 children
   - 1 pathway, 4 activities (2 pre + 2 post with drip rules)
   - 1 B2E Teacher Assessment Instrument, 4 Child Assessment Instruments
   - 94 teacher + 94 children assessment instances, 188 activity states
   - 47 pathway assignments
3. DB verification:
   - `wp_hl_track`: track_id=1, track_code=LUTHERAN_CONTROL_2026, cohort_id=1, is_control_group=1
   - `wp_hl_cohort`: cohort_id=1, cohort_name="B2E Mastery - Lutheran Services Florida", cohort_code=B2E_LSF

### Verdict: PASS

---

## All Verifications Complete

| Check | Result | Date |
|-------|--------|------|
| V1 — Grep verification | PASS | 2026-02-25 |
| V2 — seed-demo staging | PASS | 2026-02-25 |
| V3 — seed-lutheran staging | PASS | 2026-02-25 |

Grand Rename Phase 22 is fully complete.
