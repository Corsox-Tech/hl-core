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
