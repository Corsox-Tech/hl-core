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
- `HL_Audit_Service::get_last_event( $entity_id, $action_type ): ?array` — returns the most recent `hl_audit_log` row (as ARRAY_A with `actor_name` JOIN) matching entity + action, or `null`. Uses `ORDER BY created_at DESC LIMIT 1`. **Performance note:** no composite index on `(entity_id, action_type, created_at)` today — Task 6 schema revision should add `KEY entity_action_time` if Force Resend history becomes a hot path. Single-col `entity_id` index keeps today's usage fine (low row counts).
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
- Commit: (pending below)
