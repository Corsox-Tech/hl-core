# HL Core — Grand Rename Plan

## Three Renames (execute in order)
1. **Phase A: Center → School** — All code, DB, docs, UI ✅ COMPLETE (A1-A7)
2. **Phase B: Children Assessment → Child Assessment** — All code, DB, docs, UI
3. **Phase C: Cohort → Track + CohortGroup → Cohort** — Hierarchy restructure

## New Hierarchy (Phase C)
```
Cohort  ("Lutheran Services Florida 2026" — the contract)
  ├── Track: "B2E Mastery Phase 1"   (is_control=false)
  ├── Track: "B2E Mastery Phase 2"   (is_control=false)
  └── Track: "Control Group"          (is_control=true)
        └── Pathways (Teacher, Mentor) per Track
              └── Activities per Pathway
```

## Terminology After All Renames
| Old Term | New Term | Scope |
|----------|----------|-------|
| Center | **School** | Everywhere |
| center_id | **school_id** | DB columns, PHP vars |
| OrgUnit type="center" | OrgUnit type="**school**" | DB enum |
| hl_cohort_center | **hl_track_school** | DB table |
| Children Assessment | **Child Assessment** | Labels, types |
| children_assessment | **child_assessment** | activity_type, DB tables |
| hl_children_assessment_* | **hl_child_assessment_*** | DB tables |
| [hl_children_assessment] | **[hl_child_assessment]** | Shortcode |
| Cohort (the run) | **Track** | Everywhere |
| cohort_id (FK to run) | **track_id** | DB columns, PHP vars |
| hl_cohort (run table) | **hl_track** | DB table |
| CohortGroup | **Cohort** (the container) | Everywhere |
| hl_cohort_group | **hl_cohort** | DB table |
| group_id, group_code | **cohort_id, cohort_code** | DB columns |
| Center Leader | **School Leader** | Role name |

## Execution Rules
1. Complete each phase (A, B, C) fully before starting the next
2. Within each phase, tasks must run in order (DB first, then code, then docs)
3. A4+A5+A6 can run in parallel. C4+C5+C6 can run in parallel.
4. Commit after each sub-task
5. Git branch: `feature/grand-rename`
6. Update README.md ONLY in the documentation task (A7, B3, C7)

## See README.md Phase 22 for the full Build Queue breakdown
