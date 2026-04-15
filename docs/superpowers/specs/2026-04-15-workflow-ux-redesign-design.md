# Workflow Builder UX Redesign — Design Spec

**Date:** 2026-04-15
**Status:** Draft
**Branch:** TBD (new branch off main after merging `feature/email-v2-feedback-fixes`)
**Primary user:** Chris Love (non-technical, builds all email workflows)

---

## 1. Problem

The workflow builder is a standard WordPress `form-table` with 10+ fields at equal visual weight. It looks and feels like a developer settings page, not a product. Chris — the primary user — must mentally assemble disconnected form inputs to understand what a workflow does. There is no feedback loop, no test capability, and no validation before activation.

Key deficiencies:
- Flat trigger dropdown with 22 options mixing hook-based and cron-based triggers
- No visual hierarchy — Name, Status, Trigger, Template, Conditions, Recipients, Delay, Send Window all look the same
- `coaching.session_scheduled` condition is a binary yes/no — insufficient for distinguishing scheduled vs. cancelled vs. missed sessions
- No "Send Test Email" — the #1 feature requested by the client (C.1 in feedback doc)
- No recipient preview showing who would actually receive the email
- No activation guardrails — Chris can activate a workflow with no template selected
- "Delay (minutes)" and comma-separated day abbreviations are developer-speak

## 2. Design Decisions

### 2.1 Layout: Two-Panel (Form + Live Summary)

**Left panel:** Form with card-based sections. Each section is a white card with a title, optional subtitle, and required/optional badge. Advanced options (Delay, Send Window) collapsed by default.

**Right panel:** Sticky summary panel that updates in real-time as fields are filled. Contains:
- Plain-English summary sentence
- Recipient preview with count + sample names
- Send Test Email button
- Activation checklist (guardrails)
- 24h activity summary

**Responsive:** On screens narrower than 900px, the summary panel becomes a collapsible bottom drawer (fixed to viewport bottom) with a "Show Summary" toggle, preserving the live feedback loop without requiring scroll.

**Top bar:** Replaces the current `<h2>` + back link. Contains workflow name (read-only display, synced from the Name input in Basics card), draft/active/paused status badge, Save Draft button, and Activate button.

### 2.2 Trigger: Cascading Two-Dropdown with Inline Config

Replace the flat 22-option `<select>` with a hierarchical Category → Event picker.

**Dropdown 1 — Category:**
| Category | Description |
|---|---|
| Coaching Session | Session lifecycle events + scheduling reminders |
| Classroom Visit | Visit submissions + due reminders |
| RP Session | Session lifecycle + due reminders |
| Course | LearnDash course completion + overdue |
| Enrollment | Created, pathway assigned, coach assigned |
| Assessment | Teacher/child assessment submissions |
| Schedule | Cycle dates, low engagement, account activation |

**Dropdown 2 — Event** (populated dynamically based on category):

For **Coaching Session** (fully implemented in this sprint):
| Event | Maps to trigger_key | Type | Shows timing? |
|---|---|---|---|
| Session Booked | `hl_coaching_session_created` | Hook | No |
| Session Attended | `hl_coaching_session_status_changed` + filter `attended` | Hook | No |
| Session Missed / Not Attended | `hl_coaching_session_status_changed` + filter `missed` | Hook | No |
| Session Cancelled | `hl_coaching_session_status_changed` + filter `cancelled` | Hook | No |
| Session Rescheduled | `hl_coaching_session_status_changed` + filter `rescheduled` | Hook | No |
| Scheduling Reminder | `cron:component_upcoming` + type `coaching_session_attendance` | Cron | Yes |

For **Enrollment** (fully implemented in this sprint):
| Event | Maps to trigger_key | Type | Shows timing? |
|---|---|---|---|
| Enrollment Created | `hl_enrollment_created` | Hook | No |
| Pathway Assigned | `hl_pathway_assigned` | Hook | No |
| Pathway Completed | `hl_pathway_completed` | Hook | No |
| Coach Assigned | `hl_coach_assigned` | Hook | No |

Other categories are populated in the UI and map to existing trigger_keys that already work in the backend. However, only Coaching Session and Enrollment are **tested and validated** this sprint. The other categories are usable but not QA'd.

**Event type hint:** Below the dropdowns, a colored hint bar indicates whether the event is instant ("fires immediately when the event happens") or scheduled ("runs on a timer relative to a date").

**Timing config panel** (only for scheduled events):
Appears below the hint bar when a cron-based event is selected. Contains:
- Offset: `[number] [Days/Hours/Minutes]`
- Direction: `[before/after]`
- Anchor label: human-readable (e.g., "Coaching Session Display Window Start")
- Translation line: "7 days before the coaching session display window opens"

**Anchor date:** Hardcoded per component type for this sprint (coaching = `display_window_start`, others = `complete_by`). A configurable anchor date selector (Display Window Start / Display Window End / Complete By) is shown in the mockup but is **deferred** — requires a new `trigger_date_anchor` column on `hl_email_workflow` and cron query modifications.

**Backend mapping:** The cascade is purely presentational. The JS maps Category + Event to an existing `trigger_key` value. For status-changed events, it also auto-sets the `trigger_status_filter` hidden field. The save handler receives the same payload it does today — no schema changes.

### 2.3 Conditions: Coaching Session Status Enum

Replace `coaching.session_scheduled` (boolean: "Yes — session exists" / "No — no session scheduled") with `coaching.session_status` (enum).

**New condition field:**
```php
'coaching.session_status' => array(
    'label'   => 'Coaching Session Status',
    'group'   => 'Coaching',
    'type'    => 'enum',
    'options' => array(
        'not_scheduled' => 'Not Scheduled',
        'scheduled'     => 'Scheduled',
        'attended'      => 'Attended',
        'missed'        => 'Missed',
        'cancelled'     => 'Cancelled',
        'rescheduled'   => 'Rescheduled',
    ),
),
```

**Operators:** `eq`, `neq`, `in`, `not_in`. The `in`/`not_in` operators use the existing pill input UI for multi-select.

**Backend change:** `HL_Email_Automation_Service::hydrate_context()` must query the coaching session status for the specific `component_id` in the trigger context (not just any session in the cycle). The SQL changes from:

```sql
-- Old: cycle-scoped boolean
SELECT COUNT(*) FROM hl_coaching_session
WHERE mentor_enrollment_id = %d AND cycle_id = %d
  AND session_status IN ('scheduled', 'attended')
```

To:

```sql
-- New: component-scoped status
SELECT session_status FROM hl_coaching_session
WHERE mentor_enrollment_id = %d AND component_id = %d
ORDER BY created_at DESC LIMIT 1
```

Returns the actual status string (or `not_scheduled` if no row exists). The condition evaluator already handles enum comparison — no evaluator changes needed.

**The old `coaching.session_scheduled` field is removed** from the condition registry. Any existing workflows using it will need manual migration (check for existing workflows with this condition before removing — if none exist, safe to drop).

**Future:** Same pattern for `classroom_visit.status` (`pending`, `completed`) and `rp_session.status` (`pending`, `scheduled`, `attended`, `missed`, `cancelled`). Not in this sprint.

### 2.4 Form Sections (Card Layout)

The form is organized into 5 card sections + 1 collapsed section. **Progressive disclosure:** On new workflow creation, only Cards 1 (Basics) and 2 (Trigger) are visible. Cards 3-5 + Advanced appear after a trigger is selected. This cuts initial cognitive load in half and matches the natural top-down workflow. On edit, all cards are visible immediately.

**Card 1: Basics**
- Workflow Name (text input, required)
- No status field — status lives in the top bar

**Card 2: Trigger**
- Category dropdown (required)
- Event dropdown (required, populates from category)
- Event type hint (instant vs. scheduled)
- Timing config panel (conditional, for cron events only)

**Card 3: Conditions** (badge: Optional)
- Condition builder rows (field → operator → value)
- Coaching Session Status with "is any of" / "is none of" pill selector
- AND logic indicator
- "+ Add Condition" button
- Hint: "Leave empty to match every event for this trigger"

**Card 4: Recipients** (badge: Required)
- Primary (To:) — token card grid (2 columns)
- CC — token card grid (2 columns)
- Dimmed cards for tokens not relevant to current trigger
- Disabled cards for tokens already in Primary (prevents CC overlap)
- Role-based pill input (existing)
- Static email pill input (existing)

**Card 5: Email Template** (badge: Required)
- Template dropdown
- Preview bar: template name + "Open in Builder →" link

**Collapsed: Advanced Options**
- Click to expand
- Shows current values inline when collapsed ("Delay: 0 min · Send Window: Weekdays 8am–6pm ET")
- Delay: `[number] [Minutes/Hours]` (replaces raw "Delay (minutes)" input)
- Send Window: Day checkboxes (Mon–Sun, default weekdays) + time range pickers
  - Replaces comma-separated day abbreviation text input

### 2.5 Summary Panel (Right Side)

**Plain-English summary sentence:**
Updates live as fields change. Format:
> Send **"[Template Name]"** to **[Recipient tokens]** (CC: **[CC tokens]**)
>
> **When:** **[offset]** [before/after] [Category] **[anchor]**
>
> **Only if:** [condition 1] AND [condition 2]

Unfilled fields show gray italic placeholders (e.g., *"select a template"*).

**Recipient preview:**
- Green box showing count: "Would currently match **14 users**"
- Sample names (first 3): "Sarah Johnson, Maria Garcia, David Chen +11 more"
- Fetched via existing `hl_email_recipient_count` AJAX endpoint, extended to return sample names
- Updates on recipient/condition changes (debounced 400ms, existing pattern)

**Send Test Email:**
- Yellow box with "Preview as: [enrollment dropdown]" selector showing active enrollments by name, defaulting to first active enrollment. This tells Chris whose data populates the merge tags.
- Email input (pre-filled with current admin email) + "Send Test" button
- Confirmation shows: "Test sent using Sarah Johnson's data"
- **Server-side security:** Domain allowlist enforced in `ajax_send_test()` (not just client-side). Transient-based rate limit: 5 sends per admin per 10 minutes. `manage_hl_core` capability check + nonce verification. Every send logged via `HL_Audit_Service` with admin ID, recipient address, and enrollment context used.
- Requires a template to be selected (button disabled otherwise)
- Backend: new AJAX endpoint `hl_email_send_test` that renders the template using the selected enrollment's context and sends via `wp_mail()` to the specified address
- Success/error feedback inline (green "Sent to chris@housmanlearning.com using Sarah Johnson's data" / red error message)

**Activation checklist (guardrails):**
Shown as a vertical checklist with green checkmarks or red warnings:
- [ ] Trigger configured (category + event selected)
- [ ] Template selected
- [ ] At least one recipient
- [ ] Timing offset > 0 (only for cron events)
- [ ] Matching users > 0

When changing status to Active (via top bar Activate button or form save), if any guardrail fails: show a confirmation dialog listing the failures. Block activation if template is missing (hard gate). Warn but allow for other failures.

**24h Activity:**
- Already exists in the list view. Show same data (sent/failed/pending counts) in the summary panel for existing workflows.
- "No activity yet" for new/draft workflows.

### 2.6 Top Bar

Replaces the current `<h2> Edit Workflow` + `← Back` link with a horizontal bar:

- **Left:** "← All Workflows" link + workflow name display
- **Right:** Status badge (Draft/Active/Paused) + "Save Draft" button + "Activate" button
- Activate button triggers guardrail validation before changing status

### 2.7 Send Window UX Improvement

Replace the current developer-unfriendly inputs:
- `<input type="time">` × 2 + `<input type="text" placeholder="mon,tue,wed,thu,fri">`

With:
- Day checkboxes: `[x] Mon [x] Tue [x] Wed [x] Thu [x] Fri [ ] Sat [ ] Sun`
- "Weekdays" / "Every day" / "Custom" quick toggles
- Time range: `[08:00] to [18:00]` with `<input type="time">`
- Label: "ET (Eastern Time)" — no change to timezone behavior

## 3. Milestones

The redesign ships in two milestones. M1 must ship complete before M2 begins. M2 does not enter development until a screen-share validation with Chris confirms M1 solves his actual workflow pain points.

### Milestone 1: Core UX + Send Test (~3 dev-days)

**Scope:**
- Card layout with progressive disclosure (Cards 1-2 visible initially, Cards 3-5 revealed after trigger is selected)
- Send Test Email with full security (server-side domain allowlist, transient rate limit 5/admin/10min, capability check + nonce, audit logging)
- "Preview as: [enrollment dropdown]" above Send Test button — shows whose data populates merge tags
- Activation guardrails (hard gate on missing template, soft warnings)
- Condition field migration: coded function that rewrites `coaching.session_scheduled` → `coaching.session_status` in existing workflow JSON on activation
- `coaching.session_status` enum condition with `component_id`-scoped query
- Operator labels: "is any of" / "is none of" (not "is in" / "is not in")
- Reverse mapping fallback: yellow warning banner + read-only trigger section (not raw text field)
- Minimal summary preview: template subject line + first 2 body lines in right panel
- Rollback toggle: `hl_workflow_ux_version` wp_option to switch between old and new builder
- Top bar with Save Draft + Activate buttons
- Send Window day checkboxes (replaces comma text input)

**Acceptance criteria:** All existing workflows render correctly in new layout. Migration script handles all `coaching.session_scheduled` conditions. Send Test sends to allowlisted domains only. Guardrails block activation of incomplete workflows. Smoke test 0 new failures.

### Milestone 2: Cascading Triggers + Summary Panel (scoped after validation)

**Gate:** Screen-share with Chris using M1. Confirm cascading triggers are a real need.

**Scope:**
- Cascading trigger dropdowns (Category → Event)
- Full summary panel (plain-English sentence, recipient preview with sample names, 24h activity)
- Timing config panel for cron events
- Trigger categories locked to: Coaching Session, Enrollment, Course (the 3 Chris uses). Others hidden via `hidden: true` flag — functional if existing workflows reference them, but not selectable for new workflows.
- Narrow-screen responsive: summary panel as collapsible bottom drawer

### Phase 3 (Future — post-M2 validation)

- Visual flow diagram (Trigger → Conditions → Send nodes) in summary panel
- Configurable anchor date selector per workflow
- Additional trigger categories (Classroom Visit, RP Session, Assessment, Schedule)
- Workflow folders/groups
- Draft save UX / auto-restore

## 4. What's NOT in Scope

- Multi-step workflow sequences (deferred — separate future project)
- Email template builder UX redesign (separate spec, separate session)
- New trigger_key values — hierarchy is presentational over existing keys
- Visual workflow editor (nodes/connectors) — not needed for single-step workflows
- Full email preview rendering in summary panel (subject + first lines only)

## 5. Files Affected

| File | Changes |
|---|---|
| `includes/admin/class-hl-admin-emails.php` | Rewrite `render_workflow_form()` — card layout, top bar, progressive disclosure, send test AJAX handler. Update `get_condition_fields()` — replace boolean with enum. Add `get_trigger_categories()` static registry. Add `ajax_send_test()` endpoint with server-side domain allowlist + transient rate limit + capability check + audit log. Extend `ajax_recipient_count()` to return sample names. Add condition migration function. |
| `assets/js/admin/email-workflow.js` | **Additive refactor, not full rewrite.** Preserve existing condition builder and recipient picker modules (extract into named IIFEs). Add: cascading trigger picker (M2), timing panel show/hide, summary panel sync, send test AJAX, guardrail validation, card collapse/expand, progressive disclosure. |
| `assets/css/admin.css` | New section: two-panel layout, card sections, trigger cascade, timing config, summary panel, top bar, guardrails, send test box. Replace existing `.hl-workflow-form` styles. |
| `includes/services/class-hl-email-automation-service.php` | Propagate `component_id` into context: (a) in `load_coaching_session_context()`, add `$context['component_id'] = (int) $session->component_id`, (b) for cron triggers, map `$context['component_id'] = $context['entity_id']` when entity_type is component. Change coaching session status hydration to query by `component_id`, return actual `session_status` string (or `not_scheduled` if no row). |
| `includes/services/class-hl-email-queue-processor.php` | Add `send_test_email()` method — renders template using selected enrollment's context, sends via `wp_mail()` to a single address, validates domain allowlist server-side, enforces transient rate limit, logs via `HL_Audit_Service`. |

## 6. Data Model Changes

**No schema migration required.** All changes are to PHP condition registries and JS rendering.

The `trigger_key` column values remain unchanged. The cascading UI maps Category + Event to existing trigger_key values via a JS lookup table.

**Condition migration:** A coded migration function runs on plugin activation. It queries `hl_email_workflow` for conditions JSON containing `coaching.session_scheduled`, rewrites `{field: "coaching.session_scheduled", op: "eq", value: "yes"}` to `{field: "coaching.session_status", op: "in", value: ["scheduled", "attended"]}` (and "no" → `["not_scheduled", "cancelled", "missed", "rescheduled"]`), and logs the rewrite via `HL_Audit_Service`. The old `coaching.session_scheduled` field is then removed from the condition registry.

**Rollback toggle:** New `hl_workflow_ux_version` wp_option (values: `v1`, `v2`, default `v2`). When set to `v1`, `render_workflow_form()` renders the old form-table layout. Provides instant rollback without a deploy if the new UI has issues in production.

**Deferred column:** `trigger_date_anchor VARCHAR(50)` on `hl_email_workflow` — needed when the anchor date selector is implemented. Phase 3.

## 6. Trigger Category → Event → trigger_key Mapping

This is the JS lookup table that powers the cascading dropdown. The save handler receives the resolved `trigger_key` — no backend awareness of categories.

**Visibility:** Categories with `hidden: true` are not shown in the dropdown for new workflows, but their events are still recognized during reverse mapping (edit mode) so existing workflows using those triggers render correctly. In M2, only Coaching Session, Enrollment, and Course are visible. Others are hidden until validated in Phase 3.

```javascript
var TRIGGER_MAP = {
    coaching: {
        label: 'Coaching Session',
        events: {
            booked:      { label: 'Session Booked',       key: 'hl_coaching_session_created',        type: 'hook' },
            attended:    { label: 'Session Attended',      key: 'hl_coaching_session_status_changed', type: 'hook', statusFilter: 'attended' },
            missed:      { label: 'Session Missed',        key: 'hl_coaching_session_status_changed', type: 'hook', statusFilter: 'missed' },
            cancelled:   { label: 'Session Cancelled',     key: 'hl_coaching_session_status_changed', type: 'hook', statusFilter: 'cancelled' },
            rescheduled: { label: 'Session Rescheduled',   key: 'hl_coaching_session_status_changed', type: 'hook', statusFilter: 'rescheduled' },
            reminder:    { label: 'Scheduling Reminder',   key: 'cron:component_upcoming',            type: 'cron', componentType: 'coaching_session_attendance' }
        }
    },
    enrollment: {
        label: 'Enrollment',
        events: {
            created:            { label: 'Enrollment Created',  key: 'hl_enrollment_created',  type: 'hook' },
            pathway_assigned:   { label: 'Pathway Assigned',    key: 'hl_pathway_assigned',    type: 'hook' },
            pathway_completed:  { label: 'Pathway Completed',   key: 'hl_pathway_completed',   type: 'hook' },
            coach_assigned:     { label: 'Coach Assigned',      key: 'hl_coach_assigned',      type: 'hook' }
        }
    },
    // Hidden categories — functional but not shown in dropdown until Phase 3 validation
    classroom_visit: {
        label: 'Classroom Visit',
        hidden: true,
        events: {
            submitted: { label: 'Visit Form Submitted', key: 'hl_classroom_visit_submitted', type: 'hook' },
            reminder:  { label: 'Visit Due Reminder',   key: 'cron:component_upcoming',      type: 'cron', componentType: 'classroom_visit' }
        }
    },
    rp_session: {
        label: 'RP Session',
        hidden: true,
        events: {
            created:   { label: 'Session Created',  key: 'hl_rp_session_created',        type: 'hook' },
            attended:  { label: 'Session Attended',  key: 'hl_rp_session_status_changed', type: 'hook', statusFilter: 'attended' },
            missed:    { label: 'Session Missed',    key: 'hl_rp_session_status_changed', type: 'hook', statusFilter: 'missed' },
            cancelled: { label: 'Session Cancelled', key: 'hl_rp_session_status_changed', type: 'hook', statusFilter: 'cancelled' },
            reminder:  { label: 'Session Due Reminder', key: 'cron:component_upcoming',   type: 'cron', componentType: 'reflective_practice_session' }
        }
    },
    course: {
        label: 'Course',
        events: {
            completed: { label: 'Course Completed',    key: 'hl_learndash_course_completed', type: 'hook' },
            reminder:  { label: 'Course Due Reminder',  key: 'cron:component_upcoming',      type: 'cron', componentType: 'learndash_course' },
            overdue:   { label: 'Course Overdue',       key: 'cron:component_overdue',        type: 'cron', componentType: 'learndash_course' }
        }
    },
    assessment: {
        label: 'Assessment',
        hidden: true,
        events: {
            tsa_submitted: { label: 'Teacher Assessment Submitted', key: 'hl_teacher_assessment_submitted', type: 'hook' },
            ca_submitted:  { label: 'Child Assessment Submitted',   key: 'hl_child_assessment_submitted',   type: 'hook' }
        }
    },
    schedule: {
        label: 'Schedule',
        hidden: true,
        events: {
            low_engagement:    { label: 'Low Engagement (14 days)', key: 'cron:low_engagement_14d', type: 'cron' },
            account_activated: { label: 'Account Activated',        key: 'user_register',           type: 'hook' }
        }
    }
};
```

## 7. Edit Mode: Reverse Mapping

When editing an existing workflow, the form must reverse-map the stored `trigger_key` + `trigger_status_filter` + `component_type_filter` back to Category + Event selections.

Logic:
1. Read `trigger_key` from workflow record
2. Search `TRIGGER_MAP` for an event whose `key` matches
3. If multiple matches (e.g., `hl_coaching_session_status_changed` appears for attended/missed/cancelled/rescheduled), disambiguate using `trigger_status_filter`
4. If match found with `componentType`, verify against `component_type_filter`
5. Set Category dropdown to the matching category, Event dropdown to the matching event
6. If no match found (legacy or manually-entered trigger_key), show a yellow warning banner: "This workflow uses a trigger that was configured manually. Contact support to modify it." Trigger section renders as read-only in this state — no raw text field exposed to the user

## 8. Review Summary

Spec reviewed by 4 agents across 2 rounds of debate:

**Technical review (UX Designer 7.5/10 + Backend Architect 7/10):**
- 9 issues identified, all resolved in spec. Critical: component_id propagation. High: condition migration, Send Test security, progressive disclosure.

**Business review (Sales Exec 7.5/10 + CEO 7/10):**
- 5 unified recommendations after debate. Key: break into milestones, lock categories, validate with Chris before M2.

**"Strict CEOs" challenge:**
- Rollback plan added (hl_workflow_ux_version toggle)
- Audit trail for Send Test added
- Time estimate: M1 ~3 dev-days
- Monitoring via audit logs (existing infrastructure)

All findings incorporated into Sections 2-7 above.

## 9. Mockups

Interactive mockups are in `.superpowers/brainstorm/` (local only, gitignored):
- `layout-approaches.html` — A/B/C layout comparison (selected: C)
- `design-full-mockup.html` — Full two-panel layout with preset triggers (v1, superseded)
- `design-v2-triggers.html` — Final design with cascading trigger dropdowns (interactive)
