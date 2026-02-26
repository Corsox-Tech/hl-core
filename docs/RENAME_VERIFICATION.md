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

## V2: Staging seed-demo Test

_(Pending)_

## V3: Staging seed-lutheran Test

_(Pending)_
