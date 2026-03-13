# Phase 23 — Staging Deploy & Verification Log

**Date:** 2026-02-26
**Staging URL:** https://staging.academy.housmanlearning.com

## Deployment

All Phase 23 commits (23.1–23.9) pushed to GitHub and deployed to staging.

```
78b957c docs: Phase 23.9 — child assessment restructure documentation
c32bbf1 feat: Phase 23.8 — seeders populate child track snapshots
a3d0d1f feat: Phase 23.7 — admin classroom + assessment UI updates
d3f48be feat: Phase 23.6 — child assessment frontend reconciliation + submit logic
91e5cdd feat: Phase 23.5 — instrument renderer rewrite for per-child age groups
9d83825 feat: Phase 23.4 — classroom page frontend roster management
fdd97b8 feat: Phase 23.3 — classroom roster management service
5946364 feat: Phase 23.2 — snapshot service + child age group freeze logic
65c0968 db: Phase 23.1 — child assessment restructure migration + age group helper
```

## Nuke + Seed Demo

```
wp hl-core nuke --confirm='DELETE ALL DATA'    → Success (37 tables, 1774 rows)
wp hl-core seed-demo                           → Success (26 children, 26 snapshots)
```

### Demo Snapshot Distribution

| frozen_age_group | count |
|------------------|-------|
| infant           | 2     |
| toddler          | 13    |
| preschool        | 8     |
| k2               | 3     |
| **Total**        | **26**|

All 4 age groups represented. Mixed Age Room classroom produces toddler + preschool + k2 children as expected.

## Seed Lutheran

```
wp hl-core seed-lutheran                       → Success (286 children, 286 snapshots)
```

### Lutheran Snapshot Distribution

| frozen_age_group | count |
|------------------|-------|
| infant           | 8     |
| toddler          | 86    |
| preschool        | 147   |
| k2               | 45    |
| **Total**        | **286**|

All 4 age groups represented. Real DOB data from Lutheran roster produces natural distribution weighted toward preschool (as expected for early childhood programs).

## Verification Summary

- [x] Schema migration ran cleanly (hl_child_track_snapshot table created)
- [x] hl_child_classroom_current has new columns (status, removed_by, etc.)
- [x] hl_child_assessment_childrow has new columns (frozen_age_group, instrument_id, status, skip_reason)
- [x] Demo seeder creates snapshots with mixed age groups
- [x] Lutheran seeder creates snapshots with all 4 age groups from real DOB data
- [x] Both seeders clean up snapshots on --clean
