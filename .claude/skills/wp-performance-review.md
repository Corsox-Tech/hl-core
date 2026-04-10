---
name: wp-performance-review
description: WordPress performance code review and optimization analysis. Use when reviewing WordPress PHP code for performance issues, auditing themes/plugins for scalability, optimizing WP_Query, analyzing caching strategies, checking code before launch, or detecting anti-patterns, or when user mentions "performance review", "optimization audit", "slow WordPress", "slow queries", "high-traffic", "scale WordPress", "code review", "timeout", "500 error", "out of memory", or "site won't load". Detects anti-patterns in database queries, hooks, object caching, AJAX, and template loading.
---

# WordPress Performance Review Skill

## Overview

Systematic performance code review for WordPress themes, plugins, and custom code. **Core principle:** Scan critical issues first (OOM, unbounded queries, cache bypass), then warnings, then optimizations. Report with line numbers and severity levels.

## When to Use

**Use when:**
- Reviewing PR/code for WordPress theme or plugin
- User reports slow page loads, timeouts, or 500 errors
- Auditing before high-traffic event (launch, sale, viral moment)
- Optimizing WP_Query or database operations
- Investigating memory exhaustion or DB locks

**Don't use for:**
- Security-only audits (use wp-security-review when available)
- Gutenberg block development patterns (use wp-gutenberg-blocks when available)
- General PHP code review not specific to WordPress

## Code Review Workflow

1. **Identify file type** and apply relevant checks below
2. **Scan for critical patterns first** (OOM, unbounded queries, cache bypass)
3. **Check warnings** (inefficient but not catastrophic)
4. **Note optimizations** (nice-to-have improvements)
5. **Report with line numbers** using output format below

## File-Type Specific Checks

### Plugin/Theme PHP Files
Scan for:
- `query_posts()` -> CRITICAL: Never use - breaks main query
- `posts_per_page.*-1` or `numberposts.*-1` -> CRITICAL: Unbounded query
- `session_start()` -> CRITICAL: Bypasses page cache
- `add_action.*init.*` or `add_action.*wp_loaded` -> Check if expensive code runs every request
- `update_option` or `add_option` in non-admin context -> WARNING: DB writes on page load
- `wp_remote_get` or `wp_remote_post` without caching -> WARNING: Blocking HTTP

### WP_Query / Database Code
Scan for:
- Missing `posts_per_page` argument -> WARNING: Defaults to blog setting
- `'meta_query'` with `'value'` comparisons -> WARNING: Unindexed column scan
- `post__not_in` with large arrays -> WARNING: Slow exclusion
- `LIKE '%term%'` (leading wildcard) -> WARNING: Full table scan
- Missing `no_found_rows => true` when not paginating -> INFO: Unnecessary count

### AJAX Handlers (`wp_ajax_*`, REST endpoints)
Scan for:
- `admin-ajax.php` usage -> INFO: Consider REST API instead
- POST method for read operations -> WARNING: Bypasses cache
- `setInterval` or polling patterns -> CRITICAL: Self-DDoS risk
- Missing nonce verification -> Security issue (not performance, but flag it)

### Template Files
Scan for:
- `get_template_part` in loops -> WARNING: Consider caching output
- Database queries inside loops (N+1) -> CRITICAL: Query multiplication
- `wp_remote_get` in templates -> WARNING: Blocks rendering

### JavaScript Files
Scan for:
- `$.post(` for read operations -> WARNING: Use GET for cacheability
- `setInterval.*fetch|ajax` -> CRITICAL: Polling pattern
- `import _ from 'lodash'` -> WARNING: Full library import bloats bundle
- Inline `<script>` making AJAX calls on load -> Check necessity

### Asset Registration
Scan for:
- `wp_enqueue_script` without version -> INFO: Cache busting issues
- `wp_enqueue_script` without `defer`/`async` strategy -> INFO: Blocks rendering
- `wp_enqueue_script` without conditional check -> WARNING: Assets load globally when only needed on specific pages

## Search Patterns for Quick Detection

```bash
# Critical issues - scan these first
grep -rn "posts_per_page.*-1\|numberposts.*-1" .
grep -rn "query_posts\s*(" .
grep -rn "session_start\s*(" .
grep -rn "setInterval.*fetch\|setInterval.*ajax" .

# Database writes on frontend
grep -rn "update_option\|add_option" . | grep -v "admin\|activate\|install"

# Uncached expensive functions
grep -rn "url_to_postid\|attachment_url_to_postid\|count_user_posts" .

# External HTTP without caching
grep -rn "wp_remote_get\|wp_remote_post\|file_get_contents.*http" .

# Cache bypass risks
grep -rn "setcookie\|session_start" .
```

## Severity Definitions

| Severity | Description |
|----------|-------------|
| **Critical** | Will cause failures at scale (OOM, 500 errors, DB locks) |
| **Warning** | Degrades performance under load |
| **Info** | Optimization opportunity |

## Output Format

Structure findings as:

```markdown
## Performance Review: [filename/component]

### Critical Issues
- **Line X**: [Issue] - [Explanation] - [Fix]

### Warnings
- **Line X**: [Issue] - [Explanation] - [Fix]

### Recommendations
- [Optimization opportunities]

### Summary
- Total issues: X Critical, Y Warnings, Z Info
- Estimated impact: [High/Medium/Low]
```
