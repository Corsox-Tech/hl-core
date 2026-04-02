# Component Eligibility Rules — Design & Implementation Plan

**Date:** 2026-03-31
**Author:** Mateo Gonzalez + Claude (Planning Session)
**Status:** Ready for implementation

---

## Session Handoff Prompt

**Paste this into the new Claude session:**

> You are implementing "Component Eligibility Rules" for the HL Core WordPress plugin. Read `docs/superpowers/plans/2026-03-31-component-eligibility-plan.md` FIRST — it contains the full design, code samples, and file references.
>
> **CRITICAL INSTRUCTIONS:**
> 1. Read CLAUDE.md and STATUS.md before starting
> 2. The plan has line number references that MAY BE STALE — always read the actual file content before editing. Verify you're editing the right location by matching surrounding code, not line numbers.
> 3. The plan provides SUGGESTED code — treat it as guidance, not gospel. Verify each suggestion works with the current codebase. If something doesn't match, investigate and adapt.
> 4. Use this multi-agent workflow:
>    - **Developer agent** — implements each phase sequentially (Phase 1→8)
>    - **Tech Lead reviewer** — after each phase, review the changes for correctness, edge cases, and consistency with existing patterns
>    - **QA agent** — after all phases, deploy to test server, run `wp hl-core smoke-test`, verify the 4 test scenarios manually via wp eval
> 5. **Zero-error tolerance.** Every PHP file must be syntactically valid. Every DB query must use correct column names. Every UI element must render.
> 6. After implementation, update STATUS.md (add to build queue with checkboxes) and README.md per the mandatory workflow rules in CLAUDE.md.
> 7. Deploy to test server first. Run smoke test. Only deploy to production after all tests pass.
> 8. Commit after each phase so progress is tracked.

---

## Context

The HLA LMS has pathways with components (courses, assessments, classroom visits, etc.). Currently ALL components in a pathway count toward everyone's completion percentage. But:

- **Sheena Willis** (ELCPB Mentor, Assistant Director) has no classroom — she shouldn't be required to do Child Assessment or Classroom Visits
- **Float Teachers** cover classes when teachers are absent — they do TSA but NOT Child Assessment or Classroom Visits
- **ELCPB Y1** only teachers were observed; **ELCPB Y2** mentors are also observed — this varies by cycle and should be admin-configurable

**Solution:** Two admin-configurable fields per component that control eligibility.

---

## Design

### Two new columns on `hl_component`:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `requires_classroom` | tinyint(1) | 0 | If 1, skip for users without a teaching assignment |
| `eligible_roles` | text (JSON) | NULL | If set, only matching enrollment roles are eligible. NULL = all roles |

### Use case matrix:

| Component | requires_classroom | eligible_roles | Effect |
|---|---|---|---|
| Course (TC1-TC8) | 0 | NULL | Everyone |
| Teacher Self-Assessment | 0 | NULL | Everyone |
| Child Assessment | **1** | NULL | Only users with classroom |
| Classroom Visit (Y1) | **1** | `["teacher"]` | Teachers with classroom |
| Classroom Visit (Y2) | **1** | `["teacher","mentor"]` | Teachers + mentors with classroom |
| Coaching Session | 0 | `["mentor"]` | Mentors only |

### Eligibility logic:
```
eligible = true
IF eligible_roles is set AND user's enrollment role NOT in list → eligible = false
IF requires_classroom = 1 AND user has no teaching_assignment → eligible = false
```

Both conditions must pass. Roles checked first (cheaper query).

### When ineligible:
- Component excluded from completion % denominator
- Rules engine returns `availability_status = 'not_applicable'`
- Frontend shows grayed "Not Applicable" card with no action button
- Existing components (all defaults) behave exactly as before

---

## Implementation Phases

### Phase 1: Schema Migration

**File:** `includes/class-hl-installer.php`

**1A.** In the `get_schema()` method, find the `hl_component` CREATE TABLE statement. Add two columns after `visibility` and before `status`:

```sql
requires_classroom tinyint(1) NOT NULL DEFAULT 0,
eligible_roles text NULL,
```

**1B.** Bump schema revision from 24 to 25 in `maybe_upgrade()`.

dbDelta handles adding new columns with defaults — no manual ALTER TABLE needed.

**Verify:** Read `maybe_upgrade()` and confirm the revision pattern. Read the CREATE TABLE block and confirm column placement.

---

### Phase 2: Domain Model

**File:** `includes/domain/class-hl-component.php`

Add two public properties:
```php
public $requires_classroom;
public $eligible_roles;
```

Add a helper method:
```php
public function get_eligible_roles_array() {
    if (empty($this->eligible_roles)) return array();
    if (is_array($this->eligible_roles)) return $this->eligible_roles;
    $decoded = json_decode($this->eligible_roles, true);
    return is_array($decoded) ? $decoded : array();
}
```

The constructor already uses `property_exists()` for hydration — no constructor changes needed.

**Verify:** Read the constructor to confirm the `property_exists` hydration pattern.

**Repository:** No changes needed — `create()` and `update()` pass `$data` arrays directly to `$wpdb->insert()`/`$wpdb->update()`.

---

### Phase 3: Eligibility Checking

**File:** `includes/services/class-hl-rules-engine-service.php`

**3A.** Add standalone eligibility check method:

```php
public function check_eligibility($enrollment_id, $component) {
    if (empty($component->requires_classroom) && empty($component->eligible_roles)) {
        return true;
    }
    global $wpdb;
    $t = $wpdb->prefix;

    // Check eligible_roles (cheaper query first).
    if (!empty($component->eligible_roles)) {
        $allowed = $component->get_eligible_roles_array();
        if (!empty($allowed)) {
            $user_roles_json = $wpdb->get_var($wpdb->prepare(
                "SELECT roles FROM {$t}hl_enrollment WHERE enrollment_id = %d", $enrollment_id
            ));
            $user_roles = json_decode($user_roles_json, true);
            if (!is_array($user_roles) || empty(array_intersect($user_roles, $allowed))) {
                return false;
            }
        }
    }

    // Check requires_classroom.
    if (!empty($component->requires_classroom)) {
        $has = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t}hl_teaching_assignment WHERE enrollment_id = %d", $enrollment_id
        ));
        if ($has === 0) return false;
    }

    return true;
}
```

**3B.** Update `compute_availability()` — add eligibility gate at the TOP of the method, right after `$prefix` assignment:

```php
// Eligibility gate.
$elig_data = $wpdb->get_row($wpdb->prepare(
    "SELECT requires_classroom, eligible_roles FROM {$prefix}hl_component WHERE component_id = %d",
    $component_id
));
if ($elig_data && (!empty($elig_data->requires_classroom) || !empty($elig_data->eligible_roles))) {
    $comp_obj = new HL_Component(array(
        'requires_classroom' => $elig_data->requires_classroom,
        'eligible_roles'     => $elig_data->eligible_roles,
    ));
    if (!$this->check_eligibility($enrollment_id, $comp_obj)) {
        return array(
            'availability_status' => 'not_applicable',
            'locked_reason'       => null,
            'blockers'            => array(),
            'next_available_at'   => null,
        );
    }
}
```

**Verify:** Read the current `compute_availability()` method, confirm `$prefix` is defined, confirm the return format matches existing returns.

---

### Phase 4: Admin UI

**File:** `includes/admin/class-hl-admin-pathways.php`

**4A.** In `render_component_form()`, add an "Eligibility Rules" section before the closing `</table>`. Add:
- Checkbox for `requires_classroom`
- Checkboxes for `eligible_roles` (teacher, mentor, school_leader, district_leader)

**4B.** In `save_component()`, after the `$data` array is built, add:
```php
$data['requires_classroom'] = !empty($_POST['requires_classroom']) ? 1 : 0;
$eligible_roles_raw = isset($_POST['eligible_roles']) && is_array($_POST['eligible_roles'])
    ? array_map('sanitize_text_field', $_POST['eligible_roles'])
    : array();
$data['eligible_roles'] = !empty($eligible_roles_raw) ? wp_json_encode(array_values($eligible_roles_raw)) : null;
```

**Verify:** Read both methods and find the exact insertion points. The form table and $data array may have changed since this plan was written.

---

### Phase 5: Completion Calculation

**File:** `includes/services/class-hl-reporting-service.php`

**5A.** In `compute_rollups()`:
- Update the components query to SELECT `requires_classroom, eligible_roles`
- In the foreach loop, add eligibility check — `continue` (skip) if ineligible
- Ineligible components don't add to `$total_weight`

**5B.** In `get_cycle_component_detail()`:
- Update the components query to SELECT the new columns
- Add `is_eligible` key to each result entry

**Performance note:** `check_eligibility()` runs 1-2 small queries per component. For `compute_rollups()` (single enrollment, ~10 components) this is fine. For `get_cycle_component_detail()` (many enrollments), consider batch-loading teaching assignments and roles first — but optimize only if needed.

**Verify:** Read both methods, confirm the exact query strings and loop structures.

---

### Phase 6: Frontend Rendering

Update these files to check eligibility and show "Not Applicable" for ineligible components:

**6A.** `includes/frontend/class-hl-frontend-program-page.php` — Main component cards
**6B.** `includes/frontend/class-hl-frontend-my-progress.php` — Progress cards
**6C.** `includes/frontend/class-hl-frontend-my-programs.php` — Summary completion %
**6D.** `includes/frontend/class-hl-frontend-user-profile.php` — Profile progress tab
**6E.** `includes/frontend/class-hl-frontend-team-progress.php` — Team member components
**6F.** `includes/frontend/class-hl-frontend-dashboard.php` — Available steps count

Pattern for each:
```php
$rules_engine = new HL_Rules_Engine_Service(); // or use existing instance

foreach ($components as $component) {
    if (!$rules_engine->check_eligibility($enrollment_id, $component)) {
        // Don't add to total_weight or weighted_done
        // Add to display array with status = 'not_applicable'
        continue;
    }
    // ... existing logic
}
```

**6G.** Add CSS to `assets/css/frontend.css`:
```css
.hl-pp-not-applicable { opacity: 0.5; pointer-events: none; }
.hl-pp-overlay-not-applicable { background: #f0f0f0; color: #999; }
.hl-component-not-applicable { opacity: 0.5; }
```

**Verify:** Read each frontend file's component loop to understand the exact data structures and rendering patterns before modifying.

---

### Phase 7: Verification

Run `wp hl-core smoke-test` — should have 0 new failures.

Test scenarios via `wp eval-file`:

```php
// Test 1: Check eligibility for user WITHOUT classroom
$rules = new HL_Rules_Engine_Service();
$comp = new HL_Component(array('requires_classroom' => 1, 'eligible_roles' => null));
echo "No classroom: " . ($rules->check_eligibility($mentor_enrollment_id, $comp) ? 'eligible' : 'NOT eligible') . "\n";

// Test 2: Check role-based eligibility
$comp2 = new HL_Component(array('requires_classroom' => 0, 'eligible_roles' => '["teacher"]'));
echo "Mentor with teacher-only: " . ($rules->check_eligibility($mentor_enrollment_id, $comp2) ? 'eligible' : 'NOT eligible') . "\n";
```

---

### Phase 8: Documentation

Update STATUS.md build queue with new item (checked off after completion).
Update README.md "What's Implemented" with component eligibility feature.

---

## Edge Cases

- `eligible_roles = []` (empty array) → treat as NULL (all eligible)
- `eligible_roles = ["teacher","mentor"]` AND `requires_classroom = 1` → BOTH must pass
- User with `["teacher","mentor"]` roles → eligible if ANY role matches
- Component with no eligibility rules → behaves exactly as before (backward compatible)
- After adding a teaching assignment → rollup recomputation includes previously-skipped components

---

## Critical Warnings

1. **Line numbers are SUGGESTIONS** — always verify against current file content
2. **All existing components get defaults** (requires_classroom=0, eligible_roles=NULL) — zero behavior change for existing data
3. **The `eligible_roles` JSON column stores arrays** — handle null, empty string, and empty array as "all eligible"
4. **Dual check order:** roles first (cheap), then classroom (needs extra query)
5. **Don't break rollup recomputation hooks** — the `hl_core_recompute_rollups` action must still work
