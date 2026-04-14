# BB Group Sync — Design Spec

> **Ticket:** #11 — Automation Request: B2E Group Creation & Group Assignment
> **Date:** 2026-04-14
> **Status:** Draft

## Problem

For B2E Mastery partnerships, BuddyBoss group memberships are managed manually. When enrollments are created or modified, admins must manually add/remove users from the correct BuddyBoss groups. This creates operational overhead and risk of stale memberships.

## Solution

A new `HL_BB_Group_Sync_Service` that automatically syncs BuddyBoss group memberships when enrollments change or when coach/coaching_director WP roles are assigned/removed.

## Group Structure

Three types of BuddyBoss groups, all pre-existing on the site:

| Group | Scope | Mapping Storage | Member Type |
|-------|-------|-----------------|-------------|
| Global Community | Sitewide (one group) | Plugin setting: `hl_bb_global_community_group_id` | All B2E participants as members; coaches as moderators |
| Global Mentor | Sitewide (one group, subgroup of Global Community) | Plugin setting: `hl_bb_global_mentor_group_id` | Mentors as members; coaches as moderators |
| School Group | Per school | `bb_group_id` column on `hl_orgunit` | School's enrolled users as members; coaches as moderators |

## Scope

- Only enrollments in cycles where `cycle_type = program` trigger sync.
- `cycle_type = course` and archived cycles are excluded.
- Control group cycles with `cycle_type = program` ARE included (they're still B2E).

## Data Model Changes

### Schema: `hl_orgunit` — new column

```sql
ALTER TABLE wp_hl_orgunit ADD COLUMN bb_group_id BIGINT UNSIGNED NULL;
```

Added via the installer schema revision system (next revision number).

### Plugin Settings (wp_options)

- `hl_bb_global_community_group_id` (int) — BuddyBoss group ID for Global Community
- `hl_bb_global_mentor_group_id` (int) — BuddyBoss group ID for Global Mentor

## Service: `HL_BB_Group_Sync_Service`

File: `includes/services/class-hl-bb-group-sync-service.php`

### `sync_user_groups( int $user_id ): void`

Full recompute of a participant's group memberships:

1. Query all active enrollments for `$user_id` where:
   - Cycle `cycle_type = program`
   - Cycle `status != archived`
   - Enrollment `status = active`
2. From those enrollments, derive:
   - `$has_any_enrollment` — boolean, qualifies for Global Community
   - `$school_bb_group_ids` — set of `bb_group_id` values from each enrollment's `school_id` → `hl_orgunit.bb_group_id` (skip nulls)
   - `$is_mentor` — boolean, true if any enrollment has `mentor` role (via `HL_Roles::has_role()`)
3. Build the "should be in" set:
   - If `$has_any_enrollment`: add Global Community group ID
   - If `$is_mentor`: add Global Mentor group ID
   - Add all `$school_bb_group_ids`
4. Fetch user's current BB group memberships (as regular member)
5. Diff:
   - **Add** to groups in "should be in" but not currently a member
   - **Remove** from groups currently a member but not in "should be in" (only for groups we manage — the three settings + all orgunit bb_group_ids)
6. Log each add/remove via `HL_Audit_Service`

Important: the "remove" step only touches groups that are within our managed set (the two global groups + all school groups with `bb_group_id`). We never remove a user from an unrelated BB group.

### `sync_coach_groups( int $user_id ): void`

Sync moderator status for coaches:

1. Check if user has `coach` or `coaching_director` WP role
2. Build the "all managed groups" set: Global Community + Global Mentor + all OrgUnits where `bb_group_id IS NOT NULL`
3. If user IS a coach:
   - Add as moderator to all managed groups (skip if already moderator)
4. If user is NOT a coach (role was removed):
   - Remove moderator status from all managed groups
   - If user also has qualifying enrollments, `sync_user_groups()` will re-add them as regular member
5. Log via `HL_Audit_Service`

### Helper: `get_managed_group_ids(): array`

Returns the full set of BB group IDs we manage (Global Community + Global Mentor + all school bb_group_ids). Used by both sync methods to scope the diff — we only add/remove from groups in this set.

## Hooks & Triggers

### Enrollment Hooks

| Hook | Priority | Callback |
|------|----------|----------|
| `hl_enrollment_created` | 25 | `on_enrollment_changed( $enrollment_id, $data )` |
| `hl_enrollment_updated` | 25 | `on_enrollment_changed( $enrollment_id, $data )` |
| `hl_enrollment_deleted` | 25 | `on_enrollment_deleted( $enrollment_id, $user_id )` |

`on_enrollment_changed()`:
1. Load the enrollment to get `user_id` and `cycle_id`
2. Load the cycle — if `cycle_type != program`, bail
3. Call `sync_user_groups( $user_id )`

`on_enrollment_deleted()`:
- Receives `$user_id` directly (since the enrollment row is gone)
- Calls `sync_user_groups( $user_id )`

Note: `hl_enrollment_deleted` does not currently exist. We add it to `HL_Enrollment_Service::delete_enrollment()`: read `user_id` from the enrollment row, delete the row, then fire `do_action('hl_enrollment_deleted', $enrollment_id, $user_id)`. The hook fires after deletion so that `sync_user_groups()` sees the correct remaining enrollments.

### WP Role Change Hooks

| Hook | Callback |
|------|----------|
| `set_user_role` | `on_role_changed( $user_id, $new_role, $old_roles )` |
| `add_user_role` | `on_role_added( $user_id, $role )` |
| `remove_user_role` | `on_role_removed( $user_id, $role )` |

All three check if the role in question is `coach` or `coaching_director`. If so, call `sync_coach_groups( $user_id )`.

### Guard Rails

- **BuddyBoss not active:** All hooks check `function_exists('groups_join_group')`. If false, bail silently.
- **Settings not configured:** If Global Community or Global Mentor group IDs are empty/zero, skip those groups. Log a warning on first occurrence.
- **School has no `bb_group_id`:** Skip that school's group silently.
- **BB group deleted:** BP functions return false. Log a warning via `HL_Audit_Service`, don't crash.

## Admin UI

### Settings Page — "BuddyBoss Groups" Section

Added to the existing `HL_Admin_Settings` page. Two dropdowns populated via `groups_get_groups()`:

- **Global Community Group** → saves `hl_bb_global_community_group_id`
- **Global Mentor Group** → saves `hl_bb_global_mentor_group_id`

If BuddyBoss is not active, show a notice instead of the dropdowns.

### OrgUnit Edit Form — "BuddyBoss Group" Field

Added to `HL_Admin_OrgUnits` edit form, only shown when `orgunit_type = school`:

- **BuddyBoss Group** dropdown → saves `bb_group_id` on the `hl_orgunit` row
- Includes "— None —" blank option
- Same `groups_get_groups()` helper as the settings page

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| User enrolled in 2 schools across cycles | Added to both school groups |
| One of two enrollments deactivated | Stays in school group if other enrollment qualifies |
| User is both coach and participant | Coach sync makes them moderator; if coach role removed, enrollment sync re-adds as regular member |
| Mentor role added mid-cycle (enrollment updated) | `hl_enrollment_updated` fires → recompute adds to Global Mentor |
| Mentor role removed mid-cycle | Recompute removes from Global Mentor (if no other mentor enrollment) |
| All B2E enrollments deactivated | Removed from all managed groups |
| Stored `bb_group_id` points to deleted BB group | BP returns false, warning logged, no crash |
| BuddyBoss plugin deactivated | All hooks no-op via function_exists guard |

## Files Changed / Created

| File | Action |
|------|--------|
| `includes/services/class-hl-bb-group-sync-service.php` | **Create** — new service |
| `includes/services/class-hl-enrollment-service.php` | **Modify** — add `hl_enrollment_deleted` action |
| `includes/admin/class-hl-admin-settings.php` | **Modify** — add BuddyBoss Groups section |
| `includes/admin/class-hl-admin-orgunits.php` | **Modify** — add `bb_group_id` dropdown |
| `includes/domain/repositories/class-hl-orgunit-repository.php` | **Modify** — handle `bb_group_id` in CRUD |
| `includes/domain/class-hl-orgunit.php` | **Modify** — add `bb_group_id` property |
| `hl-core.php` | **Modify** — register new service, wire hooks |
| Installer/schema | **Modify** — new revision adding `bb_group_id` column |
