# Pathway Routing Engine — Execution Prompts

Copy-paste these prompts into fresh Claude Code sessions. Each prompt is self-contained with full context. Execute in order — each task builds on the previous.

**Before starting:** Read `docs/superpowers/plans/2026-04-01-pathway-routing-plan.md` and `docs/superpowers/specs/2026-04-01-pathway-routing-design.md` for full context.

---

## Session 1: Tasks 1-4 (Core Service + Bug Fixes)

### Prompt:

```
Read these files first:
- docs/superpowers/plans/2026-04-01-pathway-routing-plan.md
- docs/superpowers/specs/2026-04-01-pathway-routing-design.md
- includes/services/class-hl-pathway-assignment-service.php
- includes/services/class-hl-import-participant-handler.php
- includes/integrations/class-hl-learndash-integration.php
- includes/services/class-hl-audit-service.php (just the log() signature)
- hl-core.php (require_once section)

Execute Tasks 1-4 from the plan sequentially. For each task:

1. Implement exactly as specified in the plan
2. After implementing, dispatch a code-review agent to verify:
   - The code matches the plan exactly
   - No syntax errors
   - All SQL uses $wpdb->prepare()
   - Audit logging uses the correct array-based signature: HL_Audit_Service::log('action', array('cycle_id' => ..., 'entity_type' => ..., 'entity_id' => ..., 'reason' => ...))
   - Role normalization returns lowercase with underscores everywhere
3. Fix any issues found by the reviewer
4. Commit each task separately with the commit message from the plan

TASK 1: Create includes/services/class-hl-pathway-routing-service.php
- Full code is in the plan under Task 1, Step 1
- Add require_once in hl-core.php after line 119 (after pathway-assignment-service)
- Commit: "feat(routing): create pathway routing service with stage definitions and rules"

TASK 2: Fix audit logging in includes/services/class-hl-pathway-assignment-service.php
- assign_pathway() around lines 58-70: change 6-arg HL_Audit_Service::log() to array format
- unassign_pathway() around lines 97-109: same fix
- The correct format is: HL_Audit_Service::log('action_name', array('cycle_id' => $val, 'entity_type' => 'pathway_assignment', 'entity_id' => $id, 'reason' => 'message'))
- Commit: "fix(audit): correct audit logging signature in pathway assignment service"

TASK 3: Rewrite sync_role_defaults() in class-hl-pathway-assignment-service.php (lines 285-337)
- Full replacement code is in the plan under Task 3, Step 1
- Key changes: tries HL_Pathway_Routing_Service::resolve_pathway() first, falls back to target_roles, assigns ONE pathway only (break 2), normalizes roles to lowercase on both sides
- Commit: "fix(routing): sync_role_defaults uses routing service first, assigns ONE pathway only"

TASK 4: Fix role normalization in class-hl-import-participant-handler.php
- resolve_role() (lines 377-392): change all return values to lowercase ('teacher' not 'Teacher', 'school_leader' not 'School Leader', etc.)
- Role-change detection (around line 316): normalize existing_roles to lowercase before comparing
- Full code for both changes is in the plan under Task 4
- Commit: "fix(import): normalize roles to lowercase, case-insensitive role-change detection"

After all 4 tasks, dispatch a final review agent to verify:
- HL_Pathway_Routing_Service::resolve_pathway() is callable (static method, file is required)
- sync_role_defaults calls resolve_pathway() correctly
- resolve_role() returns lowercase everywhere
- All 5 stages have correct course IDs: A=[30293,30295], B=[39732,39734], C=[30280,30284,30286,30288], D=[39724,39726,39728,39730], E=[31037,31332,31333,31334,31335,31387,31388]
- Routing rules are in correct priority order (C+A before C before A for mentors)
- No Title Case role strings anywhere in import handler output

Do NOT deploy yet. Just commit and push to GitHub.
```

---

## Session 2: Task 5 (Import Handler Integration)

### Prompt:

```
Read these files first:
- docs/superpowers/plans/2026-04-01-pathway-routing-plan.md (Task 5)
- docs/superpowers/specs/2026-04-01-pathway-routing-design.md
- includes/services/class-hl-import-participant-handler.php (FULL file)
- includes/services/class-hl-pathway-routing-service.php (just created in previous session)
- includes/services/class-hl-pathway-assignment-service.php (recently modified)

Execute Task 5 from the plan: Integrate routing into the import participant handler.

This task has 3 parts:

PART A: Add routing call in validate() for pathway auto-suggestion

In the validate() method, find where pathway is processed (where $raw_pathway is parsed). After the existing pathway validation logic, add code that:
1. If CSV row has explicit pathway → set pathway_source = 'csv'
2. If CSV row has NO pathway and no hard errors → call HL_Pathway_Routing_Service::resolve_pathway($user_id_or_null, $parsed_role, $cycle_id)
3. If routing returns a pathway_id → look up the name, set parsed_pathway, set routed_pathway_id, set pathway_source = 'routed' or 'default'
4. Add proposed_actions message showing the auto-routed pathway with explanation

The plan has the exact code block under Task 5, Step 1.

PART B: Update commit() for routing + stale pathway clearing

In the commit() method:
1. On UPDATE rows where role changed: delete all existing hl_pathway_assignment rows for that enrollment, set assigned_pathway_id = NULL on hl_enrollment
2. Pathway assignment priority: explicit CSV > routed_pathway_id from validate > resolve at commit time > nothing
3. REMOVE the sync_role_defaults($cycle_id) call that was at the end of the commit loop

The plan has the exact code under Task 5, Step 2.

IMPORTANT: The sync_enrollment_assigned_pathway() method is private. Use $wpdb->update() directly to null out assigned_pathway_id instead of calling that method.

PART C: Add pathway_source to prepare_js_rows in class-hl-admin-imports.php

In includes/admin/class-hl-admin-imports.php, find prepare_js_rows() and add 'pathway_source' to the participant case so the JS preview can show the source.

After implementing, dispatch TWO review agents:

AGENT 1 (spec compliance): Verify against the design spec:
- validate() shows auto-routed pathway in preview with correct source labels
- commit() priority order: explicit CSV > routed > resolve at commit time
- sync_role_defaults is NOT called at end of commit anymore
- Stale pathways are cleared on role-change UPDATE rows
- pathway_source field is passed to JS

AGENT 2 (adversarial): Try to break it:
- What happens if resolve_pathway() is called with a role that just came from resolve_role() (lowercase)? Does it work? (Yes, normalize_role handles it)
- What if routed_pathway_id was set in validate but the pathway was deleted before commit? (lookup_pathway_by_code would return null, the commit-time resolve would also fail, no pathway assigned — acceptable)
- What if the same CSV has both an explicit pathway AND the routing would suggest a different one? (Explicit wins — correct per spec)
- What about a row with status=SKIP — does it still try to route? (It shouldn't modify pathways for SKIP rows)

Fix any issues found. Commit: "feat(routing): integrate routing service into import handler, clear stale pathways on role change"

Push to GitHub when done.
```

---

## Session 3: Task 6 (Admin Form AJAX)

### Prompt:

```
Read these files first:
- docs/superpowers/plans/2026-04-01-pathway-routing-plan.md (Task 6)
- includes/admin/class-hl-admin-enrollments.php (FULL file)
- includes/services/class-hl-pathway-routing-service.php

Execute Task 6: Add pathway auto-suggest to admin enrollment form.

THREE CHANGES:

1. AJAX ENDPOINT: Add wp_ajax_hl_suggest_pathway action in __construct(). Add ajax_suggest_pathway() method that:
   - Checks nonce (hl_suggest_pathway) and capability (manage_hl_core)
   - Takes user_id, role, cycle_id from POST
   - Calls HL_Pathway_Routing_Service::resolve_pathway()
   - Returns JSON: { pathway_id, pathway_name, source: 'routed'|'default'|'none' }
   Full code is in the plan under Task 6, Step 1.

2. AUTO-ROUTE ON SAVE: In handle_save(), after the pathway assignment block (after line 206), add fallback: if no pathway was selected ($new_pathway_id is 0 and $old_pathway_id is 0), call resolve_pathway() for the first role and auto-assign.
   Full code in plan Task 6, Step 2.

3. JAVASCRIPT AUTO-SUGGEST: Add inline <script> after the pathway dropdown in the form. On cycle or role change, fire AJAX to hl_suggest_pathway. Auto-select the returned pathway if admin hasn't manually picked one. Show an italic label "Auto-suggested based on course history" or "Default for new participants".
   Full code in plan Task 6, Step 3.

IMPORTANT: Find where the enrollment form renders the pathway dropdown. The JS should be placed right after that form section, inside a <?php ?> block that outputs <script> tags.

After implementing, dispatch a review agent to check:
- Nonce is created server-side and passed to JS correctly
- AJAX fires on both cycle change AND role checkbox change
- Auto-select only triggers when dropdown is empty/unset (doesn't override admin choice)
- The save fallback doesn't re-assign if a pathway was just unassigned (checks both new and old are 0)
- No XSS: pathway_name in AJAX response is used correctly in JS

Commit: "feat(routing): AJAX pathway auto-suggest on admin enrollment form"
Push to GitHub.
```

---

## Session 4: Tasks 7-8 (Data Fixes + Deploy + Test)

### Prompt:

```
Read these files first:
- docs/superpowers/plans/2026-04-01-pathway-routing-plan.md (Tasks 7-8)
- includes/cli/class-hl-cli-setup-elcpb-y2-v2.php (search for target_roles near Streamlined)
- includes/cli/class-hl-cli-seed-beginnings.php (search for target_roles near Streamlined)
- .claude/skills/deploy.md

Execute Tasks 7-8:

TASK 7: Fix District Leader target_roles in seeders

1. In includes/cli/class-hl-cli-setup-elcpb-y2-v2.php, find where Streamlined Phase 1 and Phase 2 pathways are created. Their target_roles currently say array('school_leader'). Change to array('school_leader', 'district_leader').

2. Same fix in includes/cli/class-hl-cli-seed-beginnings.php.

Commit: "fix(data): add district_leader to Streamlined pathway target_roles in seeders"

TASK 8: Deploy + Fix Live Data + Test

1. Push all commits to GitHub (auto-deploys to prod via Hostinger).

2. Deploy to test server via SCP:
   cd "C:/Users/MateoGonzalez/Dev Projects Mateo/housman-learning-academy/app/public/wp-content/plugins/hl-core"
   tar --exclude='.git' --exclude='data' --exclude='vendor' --exclude='node_modules' -czf /tmp/hl-core.tar.gz -C .. hl-core
   scp -i ~/.ssh/hla-test-keypair.pem /tmp/hl-core.tar.gz bitnami@44.221.6.201:/tmp/
   ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'cd /opt/bitnami/wordpress/wp-content/plugins && sudo rm -rf hl-core && sudo tar -xzf /tmp/hl-core.tar.gz && sudo chown -R bitnami:daemon hl-core'

3. Fix live Streamlined pathway data on test server (add district_leader to target_roles):
   ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress eval "global \$wpdb; \$prefix = \$wpdb->prefix; \$rows = \$wpdb->get_results(\"SELECT pathway_id, pathway_name, target_roles FROM {\$prefix}hl_pathway WHERE pathway_name LIKE \\\"%Streamlined%\\\" AND active_status = 1\"); foreach (\$rows as \$r) { \$roles = json_decode(\$r->target_roles, true); if (is_array(\$roles) && !in_array(\"district_leader\", \$roles)) { \$roles[] = \"district_leader\"; \$wpdb->update(\"{\$prefix}hl_pathway\", array(\"target_roles\" => wp_json_encode(\$roles)), array(\"pathway_id\" => \$r->pathway_id)); WP_CLI::log(\"Updated: \" . \$r->pathway_name); } } WP_CLI::success(\"Done.\");"'

4. Flush cache:
   ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress cache flush'

5. Verify routing works on test server — run this smoke test:
   ssh -i ~/.ssh/hla-test-keypair.pem bitnami@44.221.6.201 'export PATH=/opt/bitnami/php/bin:/opt/bitnami/mariadb/bin:/usr/local/bin:/usr/bin:/bin && wp --path=/opt/bitnami/wordpress eval "
   // Test 1: Routing service exists and is callable
   \$result = HL_Pathway_Routing_Service::resolve_pathway(null, \"teacher\", 5);
   WP_CLI::log(\"New teacher in cycle 5: pathway_id = \" . (\$result ?: \"null\"));

   // Test 2: Check stage definitions
   \$stages = HL_Pathway_Routing_Service::get_stage_definitions();
   WP_CLI::log(\"Stages defined: \" . implode(\", \", array_keys(\$stages)));

   // Test 3: Normalize role
   WP_CLI::log(\"Normalize 'School Leader' = \" . HL_Pathway_Routing_Service::normalize_role(\"School Leader\"));
   WP_CLI::log(\"Normalize 'teacher' = \" . HL_Pathway_Routing_Service::normalize_role(\"teacher\"));
   WP_CLI::log(\"Normalize 'MENTOR' = \" . HL_Pathway_Routing_Service::normalize_role(\"MENTOR\"));

   WP_CLI::success(\"Routing service smoke test complete.\");
   "'

6. Update STATUS.md — add under the Import Module Redesign section:
   ### Pathway Routing Engine (Active — April 2026)
   > **Spec:** docs/superpowers/specs/2026-04-01-pathway-routing-design.md | **Plan:** docs/superpowers/plans/2026-04-01-pathway-routing-plan.md
   - [x] **Routing service** — HL_Pathway_Routing_Service with 5 stages (A=Mentor S1, B=Mentor S2, C=Teacher S1, D=Teacher S2, E=Streamlined S1), 10 routing rules, resolve_pathway() checks LearnDash completion at user level.
   - [x] **Bug fix: audit logging** — Pathway assignment service audit calls corrected to array-based format.
   - [x] **Bug fix: sync_role_defaults** — Routing first + target_roles fallback, ONE pathway per enrollment (was assigning ALL matching).
   - [x] **Bug fix: role normalization** — All role storage normalized to lowercase. Import handler + sync comparison fixed.
   - [x] **Import integration** — Auto-routes pathway in preview + commit. Clears stale pathways on role-change UPDATE.
   - [x] **Admin form AJAX** — Auto-suggests pathway when cycle + role selected. Admin can override.
   - [x] **Data fix: district_leader** — Added to Streamlined pathway target_roles in seeders + live DB.

7. Update README.md — add PathwayRoutingService to services list.

Commit docs: "docs: update STATUS.md and README.md for pathway routing engine"
Push to GitHub.
```

---

## Verification Checklist (Run After All Sessions)

After all 4 sessions are complete, open a new session and run this verification:

```
Read these files and verify the complete routing implementation:
- includes/services/class-hl-pathway-routing-service.php
- includes/services/class-hl-pathway-assignment-service.php
- includes/services/class-hl-import-participant-handler.php
- includes/admin/class-hl-admin-enrollments.php

Run the following checks:

1. ROUTING SERVICE:
   - Has exactly 5 stages: A (2 courses), B (2 courses), C (4 courses), D (4 courses), E (7 courses)
   - Has exactly 10 routing rules in correct priority order
   - resolve_pathway() is static, accepts (int|null, string, int)
   - normalize_role() handles all formats: "Teacher", "teacher", "School Leader", "school_leader", "DISTRICT_LEADER"
   - get_completed_stages() gracefully handles: null user_id, LearnDash inactive, no completions

2. PATHWAY ASSIGNMENT SERVICE:
   - audit log in assign_pathway() uses array format (not 6 positional args)
   - audit log in unassign_pathway() uses array format
   - sync_role_defaults() tries routing first, falls back to target_roles
   - sync_role_defaults() assigns ONE pathway per enrollment (has break mechanism)
   - Role comparison normalizes both sides to lowercase

3. IMPORT HANDLER:
   - resolve_role() returns lowercase: 'teacher', 'mentor', 'school_leader', 'district_leader'
   - validate() auto-routes when no pathway in CSV, sets pathway_source
   - commit() priority: explicit CSV > routed_pathway_id > resolve at commit time
   - commit() clears stale pathways on role-change UPDATE
   - sync_role_defaults is NOT called at end of commit
   - Role change detection is case-insensitive

4. ADMIN ENROLLMENT FORM:
   - AJAX endpoint hl_suggest_pathway exists with nonce + capability check
   - Save handler auto-routes when pathway left blank
   - JS fires on cycle change AND role change
   - JS doesn't override admin's manual selection

5. SEEDERS:
   - Streamlined pathways include 'district_leader' in target_roles

Report any discrepancies.
```
