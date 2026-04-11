# Email System v2 — Build Journal

Running log of every completed task. Subagents receive this verbatim in their prompts so they understand:
- What helpers already exist and their exact signatures
- What naming conventions have been established
- What deviations from the plan have been made and why
- What files have been touched recently

Newest tasks go at the **bottom**. Append-only.

---

## Conventions so far

- Track 3 files use `<?php` open tag + single-line `ABSPATH` guard: `if ( ! defined( 'ABSPATH' ) ) exit;`
- New classes use `HL_` prefix; files named `class-hl-<slug>.php`; CLI classes live in `includes/cli/`; services in `includes/services/`
- PHP 7.4 type hints ARE used on new methods (return types + scalar params). No `mixed` (8.0+ only).
- Option keys defined as class constants, not magic strings
- CLI test harness pattern: private `assert_true`/`assert_equals` helpers, `$pass`/`$fail` counters, `WP_CLI::halt(1)` on failure, groups dispatched by name via `--only=<group>`
- New shared helpers load via a `// Shared helpers` sub-block in `hl-core.php` above the `// Email system` block (sits at line 131-133 as of Task 1)

## Helpers already available

- `HL_Audit_Service::log( $action_type, $data = array() )` — now never throws, never blocks caller. Internally: try/catch wrap + `$wpdb->insert === false` check both route to `self::record_audit_failure()` which error_logs `[HL_AUDIT_FAIL]` + bumps daily counter `hl_audit_fail_count_YYYY-MM-DD` (autoload=no). The counter bump is itself wrapped in try/catch for hard-DB-outage safety.
- `HL_Audit_Service::get_last_event( $entity_id, $action_type ): ?array` — returns the most recent `hl_audit_log` row (as ARRAY_A with `actor_name` JOIN) matching entity + action, or `null`. Uses `ORDER BY created_at DESC LIMIT 1`. **Performance note:** no composite index on `(entity_id, action_type, created_at)` today — Task 6 schema revision should add `KEY entity_action_time` if Force Resend history becomes a hot path. Single-col `entity_id` index keeps today's usage fine (low row counts). **Caller note:** only works when the audit row has a non-null `entity_id` — the `email_token_alias_hit` audit doesn't pass one, so tests for it must query `hl_audit_log` directly by `action_type`.
- `HL_Email_Recipient_Resolver` now supports 3 new token behaviors (Track 3 Task 23):
  - `assigned_mentor` — new token. Resolves via `hl_team_membership` (`membership_type='mentor'`), scoped to cycle via `hl_team.cycle_id`. Requires `user_id` + `cycle_id` in context; audit-logs `email_resolver_missing_context` on missing context. Excludes self-mentor case (triggering user who is themselves their team's mentor) with `mentor_tm.enrollment_id <> user_tm.enrollment_id`.
  - `observed_teacher` — renamed from `cc_teacher`. Reads BOTH `observed_teacher_user_id` (new) and `cc_teacher_user_id` (legacy) context keys so existing callers keep working.
  - `cc_teacher` — legacy alias, routes to `resolve_observed_teacher()` + emits `email_token_alias_hit` audit on every call (A.6.11 deprecation telemetry, removal target: 90 days of zero hits). Only current caller is `class-hl-email-automation-service.php:413` for classroom visit emails — low volume, no flood risk near-term.
  - The resolver class docblock token list now reads `triggering_user, assigned_coach, assigned_mentor, school_director, observed_teacher (alias: cc_teacher), role:X, static:email`.
- `HL_Email_Condition_Evaluator` (`includes/services/class-hl-email-condition-evaluator.php`) — now routes `enrollment.roles` conditions through `HL_Roles`. The branch is added via an early-return `if` block just above the generic `switch ( $op )` inside `evaluate_single()`. If you need to route another polymorphic field (e.g. `team.roles`), follow the same pattern: guard on `$field === '<name>' && class_exists('HL_Roles')`, handle each op, early-return per case. Unsupported ops (`gt`/`lt`) return `false` explicitly.
- `HL_Roles` (`includes/services/class-hl-roles.php`) — static-only, 4 methods:
  - `HL_Roles::parse_stored( mixed $stored ): array` — format-agnostic reader. Accepts JSON array, CSV, array, or null/empty. JSON objects (`{...}`) rejected → `[]`. Non-string entries filtered. Lowercased + trimmed + deduped. Order of returned slugs is not guaranteed.
  - `HL_Roles::has_role( mixed $stored, string $role ): bool` — exact-match role check. Fixes the `LIKE '%leader%'` substring bug.
  - `HL_Roles::sanitize_roles( $roles ): string` — normalise to canonical CSV (sorted alphabetically). Drops entries containing `,`. Returns `''` on empty/invalid.
  - `HL_Roles::scrub_is_complete(): bool` — reads `HL_Roles::OPTION_SCRUB_DONE` (`'hl_roles_scrub_done'`). Callers gate `FIND_IN_SET` usage on this.
  - Constant: `HL_Roles::OPTION_SCRUB_DONE = 'hl_roles_scrub_done'`
- `HL_CLI_Email_V2_Test` (`includes/cli/class-hl-cli-email-v2-test.php`) — CLI test harness registered as `wp hl-core email-v2-test`. Groups: `roles` (filled), `schema`/`cron`/`drafts`/`resolver`/`deliverability`/`audit` (stubs — filled by later tasks). Support `--only=<group>` flag.

## Known deviations from plans

- **Task 1** placed the `HL_Roles` require in a new `// Shared helpers` sub-block above the `// Email system` block in `hl-core.php`, rather than literally "immediately before `class-hl-email-recipient-resolver.php`" as the plan text said. Phase B and Phase D reviewers both accepted this as a reasonable grouping for a generic (non-email) helper.
- **Task 1** added type hints (PHP 7.4 scalar + return type) and a class constant (`OPTION_SCRUB_DONE`) beyond the literal plan text, per Phase D Sr SWE feedback. The plan's verbatim PHP had no type hints and used a magic option string.
- **Task 1** added a `{` rejection branch to `parse_stored()` so JSON objects yield `[]` instead of being tokenised as CSV garbage. This is new behaviour not specified in the plan but matches the docblock contract and closes a latent quirk Phase D flagged.

---

## Task log

### 2026-04-11 — Track 3 Task 1: HL_Roles helper class
- Created `includes/services/class-hl-roles.php` (~117 lines): 4 static methods, 1 class constant, PHP 7.4 type hints, JSON + CSV format-agnostic parser with `{...}` rejection.
- Created `includes/cli/class-hl-cli-email-v2-test.php` (~155 lines): CLI harness + filled `test_roles()` with 12 assertions covering `has_role` both formats both polarities, `sanitize_roles` happy + poison, `parse_stored` null/empty/object/mixed-type edges, `scrub_is_complete` both states with option cleanup.
- Modified `hl-core.php`: added `// Shared helpers` sub-block + require of `class-hl-roles.php` (line 131-133), added require + register for the CLI test class.
- Quality gate passed: Phase B (2 reviewers PASS), Phase D (Sr SWE 7.2/10 PASS, WP Expert 9.4/10 PASS). Phase E applied 4 hardening fixes inline (type hints, const, docblock fix, JSON object rejection, expanded tests). Phase G: 0 user-visible error risk (no callers yet — Task 2 will be the first consumer).
- **NOT YET DEPLOYED.** Syntax will be verified on test server at the 4-task foundation checkpoint (after Tasks 1, 2, 5, 23).
- Commit: `c1b6e86`

### 2026-04-11 — Track 3 Task 2: Route condition evaluator through HL_Roles
- Modified `includes/services/class-hl-email-condition-evaluator.php` (+37 lines): inserted a role-aware branch in `evaluate_single()` between `resolve_field()` and the generic `switch ( $op )`. Branch activates on `$field === 'enrollment.roles'`, routes `eq`/`neq`/`in`/`not_in`/`is_null`/`not_null` through `HL_Roles::has_role()` / `parse_stored()`, rejects unsupported ops (`gt`/`lt`) with explicit `return false`.
- Modified `includes/cli/class-hl-cli-email-v2-test.php` (+93 lines): replaced `test_resolver()` stub with 13 assertions covering JSON + CSV happy path, false-match regression for both formats, all 6 op boundaries, case-insensitive matching, and unsupported-op rejection.
- Quality gate: Phase B combined reviewer PASS, Phase D Sr SWE 8.5/10 PASS, WP Expert 9.4/10 PASS. Phase E added docblock note on `is_null`/`not_null` semantic divergence + 6 boundary assertions (`is_null` on PHP null, `neq` positive, `not_null` positive, unsupported op rejection, `in` zero-match, `neq` negative symmetry, case-insensitive match). Phase G: 0 user-visible error risk (workflows using valid role values — `teacher`, `mentor`, `coach`, `school_leader` — don't collide under strict matching).
- Deferred design concerns (NOT applied in Task 2, belong to a future refactor): hard-coded `'enrollment.roles'` string literal could be extensibility-scoped via `HL_Roles::is_role_field()` registry, `class_exists('HL_Roles')` guard is defensive-but-smelly (could be hard-required), request-scoped memoization on `parse_stored` for high-throughput evaluation paths. All flagged by Phase D Sr SWE; all out of scope for Task 2's plan text.
- **First consumer of `HL_Roles`** — proves the helper works via the CLI test flow. Still not deployed; syntax verified at the 4-task foundation checkpoint.
- Commit: `41d8907`

### 2026-04-11 — Track 3 Task 5: HL_Audit_Service::get_last_event + hardened log()
- Modified `includes/services/class-hl-audit-service.php` (+62/-16): wrapped `log()` body in `try { ... } catch ( \Throwable $e )`, added `$wpdb->insert === false` check, factored the failure path into a new private `record_audit_failure()` helper that itself catches any Throwable. Added public `get_last_event( $entity_id, $action_type ): ?array` with `LEFT JOIN` on `{$wpdb->users}` for `actor_name`. Fixed a pre-existing `cycle_id` column-alignment typo.
- Modified `includes/cli/class-hl-cli-email-v2-test.php` (+39/-1): filled `test_audit()` with 3 assertions + `$wpdb->delete` cleanup of the test rows so we don't pollute the audit log.
- Quality gate: combined Phase B+D reviewer PASS. One real hole caught: `$wpdb->insert` returns `false` on SQL errors WITHOUT throwing, so the plan's verbatim `try/catch` alone would miss the most common failure mode. Fixed inline by adding the return-value check + extracting a shared `record_audit_failure` helper. Schema concern noted: `hl_audit_log` has single-col indexes on `entity_id` and `action_type` but no composite `(entity_id, action_type, created_at)` — fine for today's low row counts, flag for Task 6 schema revision if Force Resend history becomes hot. Phase G: 0 user-visible error risk (all changes are infrastructure hardening; happy-path behavior identical to pre-Task-5).
- Track 1 Task 14 (Force Resend history display) now has its required helper.
- Commit: `8cbaa3a`

### 2026-04-11 — Track 3 Task 23: assigned_mentor resolver + cc_teacher alias
- Modified `includes/services/class-hl-email-recipient-resolver.php` (+102/-5): added `assigned_mentor` token case, renamed `resolve_cc_teacher()` → `resolve_observed_teacher()` with dual context key support, added new `resolve_assigned_mentor()` with 3-step SQL (enrollment → team mentor → user). Kept `cc_teacher` as a legacy alias that emits `email_token_alias_hit` audit on every hit. Updated class docblock token list.
- Modified `includes/cli/class-hl-cli-email-v2-test.php` (+65): appended `test_resolver` smoke test for `assigned_mentor` + `cc_teacher` alias audit verification. Uses `log_id` snapshot pattern for bounded cleanup (never wipes pre-existing deprecation telemetry) and avoids the `gmdate` vs server-TZ `CURRENT_TIMESTAMP` drift issue the combined reviewer caught.
- Quality gate: combined reviewer caught one BLOCKING bug (self-mentor edge case — a user who is themselves the team mentor would be returned as their own mentor via the self-join). Fixed inline by adding `AND mentor_tm.enrollment_id <> user_tm.enrollment_id` to the join. Two SHOULD-fix test hygiene issues (destructive cleanup, TZ-mismatched query window) also fixed inline via the log_id snapshot pattern. Weak smoke assertion (`is_array` passes on empty result) accepted as-is — strengthening it would require synthetic fixture data. Phase G: 0-1/10 user-visible error risk — `assigned_mentor` is a NEW token with no existing callers; `cc_teacher` alias's dual-key reading preserves existing caller semantics.
- Schema confirmations made during review: `hl_team_membership` has `(team_id, enrollment_id, membership_type ENUM('mentor','member'))` matching the plan's assumption. `hl_enrollment.status` enum is `('active','inactive')` — the `'warning'` literal is dead but harmless (kept for parity with existing cron queries per Pre-Flight note 6).
- **ALL FOUR TRACK 3 FOUNDATION PREREQUISITES (Tasks 1, 2, 5, 23) now committed.** Next step: deploy the branch to the test server and run `wp hl-core email-v2-test` to verify all four CLI test groups pass. If green, merge `feature/email-v2-track3-backend` to `main` and start Track 1 (which depends on these four). Remaining Track 3 tasks (6-22, 24-32, 28 tasks) can run in parallel with Track 1 after the merge.
- Commit: (pending below)
