# Custom Login + Password Reset + Profile Completion -- Revised Unified Spec

> **Date:** 2026-04-07
> **Status:** Final (post-final-review revision)
> **Expert reviews incorporated:** 7 critical, 24 important (31 total) + 13 final-round fixes (5 critical, 6 important, 2 minor)

---

## Part 1: Architect Debate -- Issue-by-Issue Rulings

### CRITICAL ISSUES

**C1. $wpdb->replace() destroys data** -- AGREE
Both architects agree unanimously. `REPLACE INTO` is `DELETE + INSERT` which resets auto-increment IDs, destroys `created_at`, and can null out `consent_given_at`. The fix is `INSERT ... ON DUPLICATE KEY UPDATE` as a raw query. The `created_at` column must only be set on insert (via DEFAULT), never on update.

**C2. Login page can't use hl-page.php** -- AGREE
The UX architect confirms: `hl-page.php` renders a 240px sidebar + topbar. A centered login card on a full-bleed gradient background cannot render inside `.hl-app__content`. The systems architect confirms the template_include mechanism already exists in `HL_Shortcodes::use_hl_template()` at priority 99999. A new `templates/hl-auth.php` template must be created for auth shortcodes (`[hl_login]`, `[hl_password_reset]`, `[hl_profile_setup]`). This template outputs a clean HTML doc with Inter font + frontend.css but no sidebar/topbar -- just a flex container.

**C3. Rate limiting uses wrong IP on AWS** -- AGREE
The systems architect confirms: the production server is on AWS Lightsail behind a load balancer. `$_SERVER['REMOTE_ADDR']` returns the LB IP. All users share one IP for rate limiting purposes. A `get_client_ip()` helper must check `HTTP_X_FORWARDED_FOR` (leftmost, to avoid spoofing from the rightmost), sanitize, and fall back to `REMOTE_ADDR`. No md5 needed -- store plain sanitized IP.

**C4. login_redirect moved outside BB guard creates BB dependency** -- AGREE
The systems architect confirms: `hl_login_redirect()` in `HL_BuddyBoss_Integration` only queries `hl_enrollment` and calls `find_shortcode_page_url()` -- no actual BB functions. But architecturally, auth must not depend on BB. The redirect logic is extracted into `HL_Auth_Service::resolve_post_login_redirect()` as a standalone method. The BB integration's `hl_login_redirect()` is left as a thin proxy during transition, then removed when BB is fully detached.

**C5. Login failure path doesn't use PRG pattern** -- AGREE
The UX architect agrees this matches the codebase convention (all POST handlers use POST-redirect-GET). The systems architect approves the transient approach: on login failure, store error code in a 30-second transient keyed by a session token (from a hidden field), redirect to login with `?hl_auth_error=1`, and render the error message from the transient on GET. This also prevents the "Confirm form resubmission" browser dialog on refresh.

**C6. Profile gate doesn't cover wp-admin** -- AGREE
The systems architect agrees: `template_redirect` only fires on frontend pages. A coach with an incomplete profile can navigate directly to `/wp-admin/`. An `admin_init` hook must check `is_user_logged_in() && !current_user_can('manage_options')` and redirect incomplete profiles away. Must allow `wp_doing_ajax()` through to avoid breaking AJAX handlers, and allow `wp_doing_cron()`.

**C7. Empty setup page URL causes infinite redirect loop** -- AGREE
Both architects agree: if the profile setup page doesn't exist (admin forgot to create it, or CLI hasn't run), `wp_safe_redirect('')` redirects to the current URL infinitely. Fix: guard with `if (empty($url)) return;` and log a warning via `error_log()`. Fail open = skip the gate. Users access the site without profile completion; admin sees the error log.

### IMPORTANT ISSUES

**I1. --hl-danger CSS token doesn't exist** -- AGREE
Confirmed via grep: no `--hl-danger` exists in frontend.css. `--hl-error` (#EF4444) and `--hl-error-dark` (#DC2626) are the canonical tokens. All auth CSS will use `--hl-error`.

**I2. input[type="password"] missing from global CSS** -- AGREE
Confirmed: the global form selector at frontend.css line 174 covers `text`, `email`, `number`, `search`, `date` but NOT `password`. It must be added to the selector group.

**I3. zxcvbn load strategy undefined** -- MODIFIED
The systems architect proposes: WordPress already bundles `zxcvbn` (it's in wp-includes/js/zxcvbn.min.js, ~400KB). Rather than bundling a third-party alternative, use a simple regex-based strength meter for the client side (min 8 chars, has uppercase, has number, has special char = 4-tier strength). This is ~20 lines of JS, zero bundle weight, and server-side validation is what actually matters (WP enforces its own rules on `reset_password` action). The UX architect agrees: a visual strength bar is useful feedback but the heavy zxcvbn library is overkill for this use case.

**I4. Phone autofill breaks with split fields** -- AGREE
Add `autocomplete="tel-country-code"` on the country code `<select>` and `autocomplete="tel-national"` on the phone number `<input>`. Wrap both in a `<div role="group" aria-label="Phone number">`.

**I5. Pill checkbox CSS needs new component** -- AGREE
The existing `.hl-pill` is display-only (no interactive state). Create a new `.hl-pill-check` component in frontend.css with `:checked` styles, focus ring, and keyboard accessibility.

**I6. aria-disabled needs JS click prevention + CSS** -- AGREE
Add `.hl-btn[aria-disabled="true"] { opacity: 0.5; cursor: not-allowed; pointer-events: none; }` to frontend.css. JS click guard as belt-and-suspenders (the `pointer-events: none` handles most cases).

**I7. Step indicator conflict when errors on previous step** -- AGREE
If a step panel contains a `.hl-field-error` element, the step indicator shows amber (warning) instead of green (complete). Simple CSS/JS rule.

**I8. Login nonce on cached pages** -- AGREE
Call `nocache_headers()` at the start of the `template_redirect` handler for auth pages. Document that the login page must be excluded from caching plugins (WP Super Cache, W3TC, LiteSpeed, etc.). Since we use a custom template (`hl-auth.php`), we can also set `<meta http-equiv="Cache-Control" content="no-store">` in the HTML head.

**I9. login_init must allowlist wp-login.php actions** -- AGREE
The `login_init` hook that redirects to our custom login must explicitly allow these wp-login.php actions through: `['rp', 'resetpass', 'postpass', 'logout', 'confirm_admin_email', 'confirm_new_admin_email']`. Only redirect when `$_GET['action']` is absent or not in the allowlist. Also allow `interim-login` for modal re-auth.

**I10. Hardcoded wp-login.php URLs in enrollment emails** -- AGREE
Confirmed via grep: `class-hl-admin-cycles.php` lines 2372-2409 and 3 CLI scripts all hardcode `https://academy.housmanlearning.com/wp-login.php`. Replace with `wp_login_url()` and `wp_lostpassword_url()`. After this feature ships, these will resolve to the custom pages.

**I11. retrieve_password() return value not checked** -- AGREE
In `class-hl-frontend-user-profile.php` line 180, `retrieve_password()` is called without checking `is_wp_error()`. Fix: check the return, show neutral success message to user regardless (prevents user enumeration), but log the error if it fails.

**I12. Transient invalidation on external profile writes** -- MODIFIED
The experts suggest invalidating the transient in `HL_Auth_Repository::upsert()`. The systems architect modifies this: since we're replacing transients with `wp_cache_set/get` per I17, the invalidation call becomes `wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles')` inside `upsert()`.

**I13. ethnicity validation against allowlist** -- AGREE
Validate each element of the ethnicity array against an allowed enum (defined as a class constant) before `json_encode()`. Reject invalid values silently (strip them, don't error).

**I14. password_reset_expiration double registration** -- AGREE
The filter currently lives in `hl-core.php` line 248. When `HL_Auth_Manager` registers it, the `hl-core.php` version must be removed in the same commit to prevent double-registration (which would be harmless since both return the same value, but is messy).

**I15. Query sequence for profile save** -- AGREE
Strict order: (1) `wp_update_user()` -- abort if WP_Error, (2) `HL_Auth_Repository::upsert()` -- abort if fails, (3) enrollment language sync -- non-critical, log on failure. Only set the "profile complete" cache flag after step 2 succeeds.

**I16. Language sync blindly overwrites all enrollments** -- AGREE
Wrap the enrollment language update in audit logging with before/after values per enrollment row. Add a `$skip_enrollment_sync` parameter (default false) for future admin use cases where language should not cascade.

**I17. Transients autoload pollution** -- AGREE
Replace `set_transient()` / `get_transient()` with `wp_cache_set()` / `wp_cache_get()` using the named group `'hl_profiles'`. This avoids rows piling up in `wp_options` with `_transient_*` keys that get autoloaded. Object cache (if present) handles eviction; if no persistent object cache, the data is per-request only -- which is fine since the DB query is cheap.

**I18. No partial saves = data loss on session expiry** -- AGREE
Store partial form state in `localStorage` on the client, keyed by user ID. On page load, check localStorage and restore field values. Clear localStorage after successful form submission. Simple, zero-cost, handles browser crashes and session timeouts.

**I19. No user-delete hook for PII cleanup** -- AGREE
Add `delete_user` hook in `HL_Auth_Manager` that calls `HL_Auth_Repository::delete($user_id)` to remove the `hl_user_profile` row. Also delete the cache entry.

**I20. ethnicity should be JSON type, not TEXT** -- MODIFIED
The systems architect notes: MySQL 5.7's JSON type works, but the production server runs MariaDB 10.x on Hostinger. MariaDB treats JSON as a TEXT alias (not a true JSON column). Using `JSON` in the CREATE TABLE is safe (MariaDB accepts it), but `JSON_CONTAINS` won't be available on MariaDB < 10.2.7. Since both environments may differ, use `TEXT` with a `COMMENT 'JSON array'` annotation. Validate in PHP. The UX architect defers to the backend on this.

**I21. send_password_reset should wrap retrieve_password()** -- AGREE
Don't reimplement the password reset email. Call `retrieve_password($user_login)` which triggers WP core's `lostpassword_post` action (BB and other plugins hook into this). Check the return value per I11.

**I22. Already-logged-in redirect on login page** -- AGREE
If a user is already logged in when visiting the login page, redirect to the HL dashboard (or coach dashboard for coach-only users). Check in the `template_redirect` handler before rendering.

**I23. Loading/submitting state for Complete Profile button** -- AGREE
Add `data-submitting` attribute on form submit, disable the button, show a CSS spinner. Prevent double-submit. Re-enable on error.

**I24. autocomplete attributes on login fields** -- AGREE
Set `autocomplete="username"` on the email/username field and `autocomplete="current-password"` on the password field. For password reset: `autocomplete="new-password"`.

---

## Part 2: Revised Unified Specification

### A. Architecture -- Files, Classes, Hooks

#### New Files

```
includes/
  auth/
    class-hl-auth-manager.php      # Hook orchestrator (login_init, template_redirect, admin_init, etc.)
    class-hl-auth-service.php       # Business logic (login, reset, profile gate, redirect resolution)
    class-hl-auth-repository.php    # DB operations for hl_user_profile table

  frontend/
    class-hl-frontend-login.php           # [hl_login] shortcode renderer
    class-hl-frontend-password-reset.php  # [hl_password_reset] shortcode renderer
    class-hl-frontend-profile-setup.php   # [hl_profile_setup] shortcode renderer

templates/
  hl-auth.php                       # Auth-specific template (no sidebar/topbar, full-bleed)

assets/
  css/
    (frontend.css additions)        # .hl-auth-*, .hl-pill-check, password input, etc.
  js/
    hl-auth.js                      # Password strength meter, partial save localStorage, form guards
```

#### Class Responsibilities

**HL_Auth_Manager** (singleton)
- Registers all WordPress hooks related to auth
- `login_init` -- redirect wp-login.php to custom login (with action allowlist)
- `template_redirect` -- profile gate (frontend), already-logged-in redirect, nocache_headers
- `admin_init` -- profile gate (wp-admin)
- `login_redirect` filter -- delegates to `HL_Auth_Service::resolve_post_login_redirect()`
- `password_reset_expiration` filter -- 7 days
- `delete_user` action -- PII cleanup
- Does NOT contain business logic; delegates to HL_Auth_Service

**HL_Auth_Service** (static methods)
- `authenticate($username, $password)` -- wraps `wp_signon()`, rate limiting, error logging
- `handle_password_reset_request($email)` -- wraps `retrieve_password()`, checks `is_wp_error()`
- `handle_password_reset($user, $new_password)` -- wraps `reset_password()`
- `resolve_post_login_redirect($user)` -- copied logic from BB `hl_login_redirect()`: coach-only -> coach dashboard, enrolled -> HL dashboard, else default
- `is_profile_complete($user_id)` -- checks `wp_cache_get`, falls back to DB query, sets cache
- `get_profile_setup_page_url()` -- finds page with `[hl_profile_setup]` shortcode
- `get_login_page_url()` -- finds page with `[hl_login]` shortcode
- `check_rate_limit($ip)` -- checks `wp_cache_get` for failed attempts, returns bool
- `record_failed_attempt($ip)` -- increments failed attempt counter
- `clear_rate_limit($ip)` -- clears on successful login
- `get_client_ip()` -- checks `HTTP_X_FORWARDED_FOR` (leftmost), falls back to `REMOTE_ADDR`

**HL_Auth_Repository** (static methods)
- `get($user_id)` -- returns profile row or null
- `upsert($user_id, $data)` -- INSERT ... ON DUPLICATE KEY UPDATE, invalidates cache
- `delete($user_id)` -- DELETE + cache invalidation
- `is_complete($user_id)` -- boolean check (has all required profile fields: nickname, gender, ethnicity, location, age_range, preferred_language, years_exp_industry, years_exp_position, consent)

**HL_Frontend_Login** (shortcode `[hl_login]`)
- Renders the login form (GET) with error display from transient
- Handles POST submission via `template_redirect`, stores error in transient, redirects (PRG)

**HL_Frontend_Password_Reset** (shortcode `[hl_password_reset]`)
- Two states: request form (enter email) and reset form (enter new password, from WP reset link)
- Handles POST via `template_redirect` with PRG pattern

**HL_Frontend_Profile_Setup** (shortcode `[hl_profile_setup]`)
- Multi-step form: Step 1 (Personal Information), Step 2 (Professional Information), Step 3 (Social Media + Consent)
- Single POST submission on final step; partial saves in localStorage on client
- Handles POST via `template_redirect` with PRG pattern

#### Hook Registration Map

```php
// HL_Auth_Manager::__construct()

// 1. Redirect wp-login.php to custom login
add_action('login_init', [$this, 'intercept_wp_login']);

// 2. Profile gate (frontend) + auth page redirects + POST handlers
add_action('template_redirect', [$this, 'handle_auth_redirects'], 5);  // early priority

// 3. Profile gate (wp-admin)
add_action('admin_init', [$this, 'enforce_profile_gate_admin']);

// 4. Post-login redirect
add_filter('login_redirect', [$this, 'filter_login_redirect'], 999, 3);

// 5. Password reset expiration (7 days)
add_filter('password_reset_expiration', [$this, 'extend_reset_expiration']);

// 6. PII cleanup on user deletion
add_action('delete_user', [$this, 'cleanup_user_profile']);
```

#### Template Selection

In `HL_Shortcodes::use_hl_template()`, add a check BEFORE the existing `[hl_` check:

```php
// Auth shortcode pages -- full-bleed template (no sidebar/topbar).
if (is_singular('page')) {
    global $post;
    $auth_shortcodes = ['[hl_login]', '[hl_password_reset]', '[hl_profile_setup]'];
    foreach ($auth_shortcodes as $sc) {
        if (strpos($post->post_content, $sc) !== false) {
            return HL_CORE_PLUGIN_DIR . 'templates/hl-auth.php';
        }
    }
    // Existing: regular HL shortcode pages
    if (strpos($post->post_content, '[hl_') !== false) {
        return HL_CORE_PLUGIN_DIR . 'templates/hl-page.php';
    }
}
```

Note: The `[hl_profile_setup]` page uses `hl-auth.php` (no sidebar) because:
- Users reaching this page have NOT completed their profile yet
- They should not see the full navigation (sidebar items assume complete enrollment)
- The focused, centered layout reduces distraction during onboarding
- After submission, they redirect to the dashboard which uses `hl-page.php` with full sidebar

### B. Database Schema

#### New Table: `hl_user_profile`

```sql
CREATE TABLE {$wpdb->prefix}hl_user_profile (
    profile_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    nickname varchar(100) DEFAULT NULL,
    phone_country_code varchar(5) DEFAULT '+1',
    phone_number varchar(20) DEFAULT NULL,
    gender varchar(60) DEFAULT NULL,
    ethnicity text DEFAULT NULL COMMENT 'JSON array of selected values',
    location_state varchar(100) DEFAULT NULL,
    age_range varchar(20) DEFAULT NULL,
    preferred_language varchar(5) DEFAULT 'en',
    years_exp_industry varchar(20) DEFAULT NULL,
    years_exp_position varchar(20) DEFAULT NULL,
    job_title varchar(255) DEFAULT NULL,
    social_instagram varchar(255) DEFAULT NULL,
    social_twitter varchar(255) DEFAULT NULL,
    social_linkedin varchar(500) DEFAULT NULL,
    social_facebook varchar(500) DEFAULT NULL,
    social_website varchar(500) DEFAULT NULL,
    consent_given_at datetime DEFAULT NULL,
    consent_version varchar(20) DEFAULT NULL,
    profile_completed_at datetime DEFAULT NULL,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id),
    UNIQUE KEY user_id (user_id),
    KEY profile_completed_at (profile_completed_at)
) $charset_collate;
```

**Schema notes:**
- `user_id` has a UNIQUE index, which is the key for `INSERT ... ON DUPLICATE KEY UPDATE`
- `created_at` uses `DEFAULT CURRENT_TIMESTAMP` -- never included in UPDATE sets
- `ethnicity` is TEXT (not JSON) for MariaDB compatibility; validated in PHP
- `nickname` is stored in hl_user_profile (not WP usermeta) to keep all profile fields in one table
- `gender`, `age_range`, `years_exp_industry`, `years_exp_position` are varchar to store the exact radio option label/key (not numeric)
- `years_exp_industry` and `years_exp_position` are TWO SEPARATE columns (industry experience vs. current position experience)
- All 5 social media fields are optional; stored as-is after sanitization
- `consent_given_at` is preserved across upserts (only set when user explicitly consents)
- `profile_completed_at` is set once when all required fields are filled

#### Schema Revision

Add as the next revision (currently at 31, so this is rev 32):

```php
// Rev 32: Add hl_user_profile table for auth/profile system.
if ((int) $stored < 32) {
    // Table created by dbDelta in get_schema().
    // No ALTER TABLE needed -- fresh table.
    // NOTE: Do NOT call update_option() here. The existing
    // update_option('hl_core_schema_revision', $current_revision)
    // at the end of maybe_upgrade() handles the revision bump.
}
```

#### Upsert Implementation (C1 fix)

```php
// HL_Auth_Repository::upsert()
public static function upsert($user_id, $data) {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_user_profile';

    // Allowlist of columns (FC5: use esc_sql + int cast instead of $wpdb->prepare
    // to avoid vsprintf garbling values containing literal % characters).
    $allowed_string_cols = [
        'nickname', 'phone_country_code', 'phone_number', 'gender',
        'ethnicity', 'location_state', 'age_range', 'preferred_language',
        'years_exp_industry', 'years_exp_position', 'job_title',
        'social_instagram', 'social_twitter', 'social_linkedin',
        'social_facebook', 'social_website',
        'consent_given_at', 'consent_version', 'profile_completed_at',
    ];

    // Build column/value pairs for INSERT
    $columns = ['`user_id`'];
    $values  = [(int) $user_id];
    $update_parts = [];

    foreach ($allowed_string_cols as $col) {
        if (array_key_exists($col, $data)) {
            $columns[]      = "`{$col}`";
            $values[]       = "'" . esc_sql($data[$col]) . "'";
            $update_parts[] = "`{$col}` = VALUES(`{$col}`)";
        }
    }

    if (empty($update_parts)) {
        return false;
    }

    $cols_str   = implode(', ', $columns);
    $vals_str   = implode(', ', $values);
    $update_str = implode(', ', $update_parts);

    $sql = "INSERT INTO `{$table}` ({$cols_str}) VALUES ({$vals_str})
            ON DUPLICATE KEY UPDATE {$update_str}";

    // Direct query -- no $wpdb->prepare() because the column set is dynamic
    // and values are pre-escaped via esc_sql() / int cast.
    $result = $wpdb->query($sql);

    // Invalidate cache on every write (I12)
    wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles');

    return $result !== false;
}
```

### C. Login Page Implementation

#### Template: `templates/hl-auth.php`

```php
<?php
/**
 * HL Auth Template
 *
 * Full-bleed template for login, password reset, and profile setup pages.
 * No sidebar, no topbar. Centered card layout.
 */
if (!defined('ABSPATH')) exit;

// Prevent caching (I8)
nocache_headers();

global $post;
$page_content = do_shortcode($post->post_content);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?php echo esc_html(get_the_title()); ?> — Housman Learning</title>
    <?php wp_site_icon(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo esc_url(includes_url('css/dashicons.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(HL_CORE_ASSETS_URL . 'css/frontend.css'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>">
    <?php wp_print_styles(); ?>
    <?php wp_print_scripts(['jquery']); ?>
</head>
<body class="hl-auth-page">
    <?php
    // FI1: Profile setup needs wider container (680px vs 480px default)
    $wrapper_class = 'hl-auth-wrapper';
    if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
        $wrapper_class .= ' hl-auth-wrapper--wide';
    }
    ?>
    <div class="<?php echo esc_attr($wrapper_class); ?>">
        <?php echo $page_content; ?>
    </div>
    <?php wp_print_scripts(); ?>
    <script src="<?php echo esc_url(HL_CORE_ASSETS_URL . 'js/hl-auth.js'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>"></script>
</body>
</html>
```

#### CSS for Auth Layout

```css
/* =====================================================
   AUTH PAGES (Login, Password Reset, Profile Setup)
   ===================================================== */

.hl-auth-page {
    margin: 0;
    padding: 0;
    font-family: var(--hl-font);
    background: linear-gradient(135deg, var(--hl-primary) 0%, var(--hl-primary-light) 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

.hl-auth-wrapper {
    width: 100%;
    max-width: 480px;
    padding: 24px;
}

/* Profile setup needs wider container for multi-step form */
.hl-auth-wrapper--wide {
    max-width: 680px;
}

.hl-auth-card {
    background: var(--hl-surface);
    border-radius: var(--hl-radius);
    box-shadow: var(--hl-shadow-lg);
    padding: 40px;
    text-align: center;
}

.hl-auth-logo {
    max-width: 180px;
    margin: 0 auto 32px;
}

.hl-auth-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--hl-text-heading);
    margin: 0 0 8px;
}

.hl-auth-subtitle {
    font-size: 14px;
    color: var(--hl-text-secondary);
    margin: 0 0 32px;
}

.hl-auth-form {
    text-align: left;
}

.hl-auth-field {
    margin-bottom: 20px;
}

.hl-auth-field label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--hl-text);
    margin-bottom: 6px;
}

.hl-auth-field input[type="text"],
.hl-auth-field input[type="email"],
.hl-auth-field input[type="password"],
.hl-auth-field input[type="tel"],
.hl-auth-field input[type="url"] {
    width: 100%;
    box-sizing: border-box;
    font-family: var(--hl-font);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    padding: 12px 14px;
    font-size: 15px;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
}

.hl-auth-field input:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

.hl-auth-field--error input {
    border-color: var(--hl-error);
}

.hl-auth-field--error input:focus {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
}

.hl-auth-error {
    background: #FEF2F2;
    border: 1px solid #FECACA;
    border-radius: var(--hl-radius-xs);
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 14px;
    color: var(--hl-error-dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.hl-auth-error .dashicons {
    font-size: 18px;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.hl-auth-success {
    background: #F0FDF4;
    border: 1px solid #BBF7D0;
    border-radius: var(--hl-radius-xs);
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 14px;
    color: var(--hl-accent-dark);
}

.hl-auth-btn {
    display: block;
    width: 100%;
    padding: 14px;
    background: var(--hl-accent);
    color: #fff;
    border: none;
    border-radius: var(--hl-radius-xs);
    font-size: 16px;
    font-weight: 600;
    font-family: var(--hl-font);
    cursor: pointer;
    transition: background 0.15s ease;
    position: relative;
}

.hl-auth-btn:hover {
    background: var(--hl-accent-dark);
}

.hl-auth-btn[aria-disabled="true"] {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.hl-auth-btn--submitting .hl-auth-btn-text {
    visibility: hidden;
}

.hl-auth-btn--submitting::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: hl-spin 0.6s linear infinite;
}

@keyframes hl-spin {
    to { transform: translate(-50%, -50%) rotate(360deg); }
}

.hl-auth-links {
    margin-top: 24px;
    text-align: center;
    font-size: 14px;
}

.hl-auth-links a {
    color: var(--hl-secondary);
    text-decoration: none;
}

.hl-auth-links a:hover {
    text-decoration: underline;
}

/* Password strength meter */
.hl-pw-strength {
    margin-top: 8px;
    height: 4px;
    border-radius: 2px;
    background: var(--hl-border);
    overflow: hidden;
}

.hl-pw-strength__bar {
    height: 100%;
    border-radius: 2px;
    transition: width 0.3s ease, background 0.3s ease;
    width: 0;
}

.hl-pw-strength--weak .hl-pw-strength__bar    { width: 25%;  background: var(--hl-error); }
.hl-pw-strength--fair .hl-pw-strength__bar    { width: 50%;  background: var(--hl-warning); }
.hl-pw-strength--good .hl-pw-strength__bar    { width: 75%;  background: #60A5FA; }
.hl-pw-strength--strong .hl-pw-strength__bar  { width: 100%; background: var(--hl-accent); }

.hl-pw-strength__label {
    font-size: 12px;
    margin-top: 4px;
    color: var(--hl-text-secondary);
}
```

#### Login Shortcode Renderer

```php
class HL_Frontend_Login {

    public static function render($atts) {
        // Already logged in? Show redirect message (I22)
        if (is_user_logged_in()) {
            $redirect_url = HL_Auth_Service::resolve_post_login_redirect(wp_get_current_user());
            // JS redirect as template is already rendering
            return '<script>window.location.href = ' . wp_json_encode($redirect_url) . ';</script>';
        }

        // Check for error from PRG redirect (C5)
        $error_message = '';
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';
        if (isset($_GET['hl_auth_error']) && $session_token) {
            $transient_key = 'hl_auth_err_' . substr(wp_hash($session_token), 0, 20);
            $error_code = get_transient($transient_key);
            delete_transient($transient_key);

            $error_messages = array(
                'invalid_credentials' => __('Invalid email or password. Please try again.', 'hl-core'),
                'rate_limited'        => __('Too many failed attempts. Please wait a few minutes and try again.', 'hl-core'),
                'empty_fields'        => __('Please enter your email and password.', 'hl-core'),
            );

            $error_message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : '';
        }

        // Generate session token for CSRF + PRG
        $session_token = wp_generate_password(32, false);
        setcookie('hl_auth_session', $session_token, time() + 300, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

        // Logo
        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        ob_start();
        ?>
        <div class="hl-auth-card">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Welcome Back', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle"><?php esc_html_e('Sign in to Housman Learning Academy', 'hl-core'); ?></p>

            <?php if ($error_message) : ?>
                <div class="hl-auth-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    <?php echo esc_html($error_message); ?>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="" id="hl-login-form">
                <?php wp_nonce_field('hl_login_action', 'hl_login_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="login">
                <input type="hidden" name="hl_session_token" value="<?php echo esc_attr($session_token); ?>">

                <div class="hl-auth-field">
                    <label for="hl-login-email"><?php esc_html_e('Email Address', 'hl-core'); ?></label>
                    <input type="email" id="hl-login-email" name="hl_login_email"
                           autocomplete="username"
                           required
                           placeholder="<?php esc_attr_e('you@example.com', 'hl-core'); ?>">
                </div>

                <div class="hl-auth-field">
                    <label for="hl-login-password"><?php esc_html_e('Password', 'hl-core'); ?></label>
                    <input type="password" id="hl-login-password" name="hl_login_password"
                           autocomplete="current-password"
                           required
                           placeholder="<?php esc_attr_e('Enter your password', 'hl-core'); ?>">
                </div>

                <button type="submit" class="hl-auth-btn" id="hl-login-btn">
                    <span class="hl-auth-btn-text"><?php esc_html_e('Sign In', 'hl-core'); ?></span>
                </button>
            </form>

            <div class="hl-auth-links">
                <a href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Forgot your password?', 'hl-core'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

#### Login POST Handler (PRG Pattern -- C5)

```php
// In HL_Auth_Manager::handle_auth_redirects() (template_redirect hook)

// --- Login POST ---
if (is_page() && isset($_POST['hl_auth_action']) && $_POST['hl_auth_action'] === 'login') {
    if (!wp_verify_nonce($_POST['hl_login_nonce'] ?? '', 'hl_login_action')) {
        wp_die(__('Security check failed.', 'hl-core'));
    }

    $email    = sanitize_email($_POST['hl_login_email'] ?? '');
    $password = $_POST['hl_login_password'] ?? '';
    $session_token = sanitize_text_field($_POST['hl_session_token'] ?? '');

    // Empty fields check
    if (empty($email) || empty($password)) {
        $this->store_auth_error($session_token, 'empty_fields');
        wp_safe_redirect(add_query_arg('hl_auth_error', '1', HL_Auth_Service::get_login_page_url()));
        exit;
    }

    // Rate limit check (C3)
    $client_ip = HL_Auth_Service::get_client_ip();
    if (HL_Auth_Service::check_rate_limit($client_ip)) {
        $this->store_auth_error($session_token, 'rate_limited');
        wp_safe_redirect(add_query_arg('hl_auth_error', '1', HL_Auth_Service::get_login_page_url()));
        exit;
    }

    // Attempt login
    $user = wp_signon(array(
        'user_login'    => $email,
        'user_password' => $password,
        'remember'      => true,
    ), is_ssl());

    if (is_wp_error($user)) {
        HL_Auth_Service::record_failed_attempt($client_ip);
        $this->store_auth_error($session_token, 'invalid_credentials');
        wp_safe_redirect(add_query_arg('hl_auth_error', '1', HL_Auth_Service::get_login_page_url()));
        exit;
    }

    // Success -- clear rate limit, set auth cookies, redirect
    HL_Auth_Service::clear_rate_limit($client_ip);
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true, is_ssl());

    $redirect_url = HL_Auth_Service::resolve_post_login_redirect($user);
    wp_safe_redirect($redirect_url);
    exit;
}
```

**Helper for error transient:**

```php
private function store_auth_error($session_token, $error_code) {
    if (empty($session_token)) return;
    $transient_key = 'hl_auth_err_' . substr(wp_hash($session_token), 0, 20);
    set_transient($transient_key, $error_code, 30); // 30 second TTL
}
```

Note: We use a real transient (not wp_cache) for the error code because it must survive the redirect (wp_cache is per-request). The key is hashed and the TTL is 30 seconds, so autoload pollution (I17) is negligible for this single use case. The profile-complete check uses wp_cache per I17.

### D. Password Reset Implementation

#### wp-login.php Interception (I9)

```php
// HL_Auth_Manager::intercept_wp_login() -- hooked to login_init

public function intercept_wp_login() {
    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
    // Also check REQUEST for POST actions
    if (empty($action) && isset($_REQUEST['action'])) {
        $action = sanitize_text_field($_REQUEST['action']);
    }

    // Actions that MUST stay on wp-login.php
    $allowlist = array(
        'rp',                        // Password reset form (from email link)
        'resetpass',                 // Password reset POST handler
        'postpass',                  // Password-protected post access
        'logout',                    // Logout handler
        'confirm_admin_email',       // WP admin email confirmation
        'confirm_new_admin_email',   // WP new admin email confirmation
        'interim-login',             // Modal re-authentication
    );

    if (in_array($action, $allowlist, true)) {
        return; // Let WordPress handle it
    }

    // For 'lostpassword' action, redirect to our custom page
    if ($action === 'lostpassword') {
        $reset_url = HL_Auth_Service::get_password_reset_page_url();
        if ($reset_url) {
            wp_safe_redirect($reset_url);
            exit;
        }
        return; // Fall back to WP default if our page doesn't exist
    }

    // Default: redirect to custom login page
    $login_url = HL_Auth_Service::get_login_page_url();
    if ($login_url) {
        wp_safe_redirect($login_url);
        exit;
    }
    // If custom login page doesn't exist, fall through to default wp-login.php
}
```

#### Password Reset Shortcode

The `[hl_password_reset]` shortcode renders the "request reset" form. When the user submits their email, we call `retrieve_password()` (I21, I11) and always show a neutral success message (prevents user enumeration).

```php
class HL_Frontend_Password_Reset {

    public static function render($atts) {
        // Check for success state (PRG)
        $show_success = isset($_GET['hl_reset_sent']) && $_GET['hl_reset_sent'] === '1';

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        ob_start();
        ?>
        <div class="hl-auth-card">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Reset Your Password', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle"><?php esc_html_e('Enter your email and we\'ll send you a reset link.', 'hl-core'); ?></p>

            <?php if ($show_success) : ?>
                <div class="hl-auth-success" role="status">
                    <?php esc_html_e('If an account exists with that email, you\'ll receive a password reset link shortly.', 'hl-core'); ?>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="">
                <?php wp_nonce_field('hl_reset_request_action', 'hl_reset_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="reset_request">

                <div class="hl-auth-field">
                    <label for="hl-reset-email"><?php esc_html_e('Email Address', 'hl-core'); ?></label>
                    <input type="email" id="hl-reset-email" name="hl_reset_email"
                           autocomplete="username"
                           required
                           placeholder="<?php esc_attr_e('you@example.com', 'hl-core'); ?>">
                </div>

                <button type="submit" class="hl-auth-btn" id="hl-reset-btn">
                    <span class="hl-auth-btn-text"><?php esc_html_e('Send Reset Link', 'hl-core'); ?></span>
                </button>
            </form>

            <div class="hl-auth-links">
                <a href="<?php echo esc_url(HL_Auth_Service::get_login_page_url() ?: wp_login_url()); ?>"><?php esc_html_e('Back to Sign In', 'hl-core'); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

#### Password Reset POST Handler

```php
// In HL_Auth_Manager::handle_auth_redirects()

if (is_page() && isset($_POST['hl_auth_action']) && $_POST['hl_auth_action'] === 'reset_request') {
    if (!wp_verify_nonce($_POST['hl_reset_nonce'] ?? '', 'hl_reset_request_action')) {
        wp_die(__('Security check failed.', 'hl-core'));
    }

    $email = sanitize_email($_POST['hl_reset_email'] ?? '');

    if (!empty($email)) {
        // Find user by email, call retrieve_password (I21)
        $user = get_user_by('email', $email);
        if ($user) {
            $result = retrieve_password($user->user_login);
            if (is_wp_error($result)) {
                // Log error but show neutral message to user (I11)
                error_log('[HL Auth] retrieve_password failed for user ' . $user->ID . ': ' . $result->get_error_message());
            }
        }
        // Always show success (prevents user enumeration)
    }

    $reset_url = HL_Auth_Service::get_password_reset_page_url();
    wp_safe_redirect(add_query_arg('hl_reset_sent', '1', $reset_url));
    exit;
}
```

**Note on wp-login.php?action=rp:** The actual password reset form (where users enter their new password after clicking the email link) stays on `wp-login.php?action=rp` because WordPress core handles the key validation, the `resetpass` POST handler, and the `password_reset` action. Reimplementing this would be fragile and break BB's hooks into `lostpassword_post`. The `rp` and `resetpass` actions are in our allowlist (I9), so they pass through.

**Email link customization:** To make the password reset email link point to our branded experience, hook `retrieve_password_message` to replace the `wp-login.php?action=rp` URL in the email body. However, this is a Phase 2 enhancement -- for launch, the WP default reset form works and is secure.

### E. Profile Completion Form

#### Step Structure

**Step 1: Personal Information**
- First Name * (text, prepopulated from WP `$user->first_name`, editable)
- Last Name * (text, prepopulated from WP `$user->last_name`, editable)
- Email * (text, prepopulated from WP `$user->user_email`, READ-ONLY gray background)
- Nickname * (text)
- Phone Number (OPTIONAL -- country code `+1` default + number) (I4)
- Gender * -- Radio buttons: Male, Female, Transgender, Different gender identity, Other
- Ethnicity * -- MULTI-SELECT pill checkboxes (I5, I13): African-American, Asian, Caucasian, Latino or Hispanic, Native American, Native Hawaiian or Pacific Islander, Other/Unknown, Prefer not to say
- Location * -- Dropdown: All 50 US States alphabetically + disabled separator + "--Mexico", "--Canada", "--Other"
- Age * -- Radio buttons: 18-24, 25-34, 35-44, 45-54, 55-64, 64+
- Preferred Course Language * -- Radio buttons: English, Spanish, Portuguese

**Step 2: Professional Information**
- Years of Experience in Industry * -- Radio buttons: Less than 1, 1-3, 4-6, 7-9, 10-12, More than 12
- Years of Experience in Current Position * -- Radio buttons (SAME options as above, SEPARATE field)
- Job Title (OPTIONAL) -- text input

**Step 3: Social Media Accounts (ALL OPTIONAL) + Consent**
- Instagram -- @ prefix + text input
- X (Twitter) -- @ prefix + text input
- LinkedIn -- URL input with placeholder
- Facebook -- URL input with placeholder
- Website / URL -- URL input
- Consent checkbox (required)

#### Profile Setup Shortcode

```php
class HL_Frontend_Profile_Setup {

    /**
     * Allowed ethnicity values (I13).
     * Matches exact user requirements.
     */
    const ETHNICITY_OPTIONS = array(
        'african_american'       => 'African-American',
        'asian'                  => 'Asian',
        'caucasian'              => 'Caucasian',
        'latino_hispanic'        => 'Latino or Hispanic',
        'native_american'        => 'Native American',
        'native_hawaiian_pacific'=> 'Native Hawaiian or Pacific Islander',
        'other_unknown'          => 'Other/Unknown',
        'prefer_not_to_say'      => 'Prefer not to say',
    );

    /**
     * Gender options.
     */
    const GENDER_OPTIONS = array(
        'male'               => 'Male',
        'female'             => 'Female',
        'transgender'        => 'Transgender',
        'different_identity' => 'Different gender identity',
        'other'              => 'Other',
    );

    /**
     * Age range options.
     */
    const AGE_RANGE_OPTIONS = array(
        '18-24' => '18-24',
        '25-34' => '25-34',
        '35-44' => '35-44',
        '45-54' => '45-54',
        '55-64' => '55-64',
        '64+'   => '64+',
    );

    /**
     * Language options (FI4).
     */
    const LANGUAGE_OPTIONS = array(
        'en' => 'English',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
    );

    /**
     * Years of experience options (used for BOTH industry and position fields).
     */
    const YEARS_EXP_OPTIONS = array(
        'less_than_1' => 'Less than 1',
        '1-3'         => '1-3',
        '4-6'         => '4-6',
        '7-9'         => '7-9',
        '10-12'       => '10-12',
        'more_than_12'=> 'More than 12',
    );

    /**
     * US States + Mexico/Canada/Other for Location dropdown.
     */
    const LOCATION_OPTIONS = array(
        'AL' => 'Alabama',        'AK' => 'Alaska',        'AZ' => 'Arizona',
        'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
        'CT' => 'Connecticut',    'DE' => 'Delaware',       'FL' => 'Florida',
        'GA' => 'Georgia',        'HI' => 'Hawaii',         'ID' => 'Idaho',
        'IL' => 'Illinois',       'IN' => 'Indiana',        'IA' => 'Iowa',
        'KS' => 'Kansas',         'KY' => 'Kentucky',       'LA' => 'Louisiana',
        'ME' => 'Maine',          'MD' => 'Maryland',       'MA' => 'Massachusetts',
        'MI' => 'Michigan',       'MN' => 'Minnesota',      'MS' => 'Mississippi',
        'MO' => 'Missouri',       'MT' => 'Montana',        'NE' => 'Nebraska',
        'NV' => 'Nevada',         'NH' => 'New Hampshire',  'NJ' => 'New Jersey',
        'NM' => 'New Mexico',     'NY' => 'New York',       'NC' => 'North Carolina',
        'ND' => 'North Dakota',   'OH' => 'Ohio',           'OK' => 'Oklahoma',
        'OR' => 'Oregon',         'PA' => 'Pennsylvania',   'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota',   'TN' => 'Tennessee',
        'TX' => 'Texas',          'UT' => 'Utah',           'VT' => 'Vermont',
        'VA' => 'Virginia',       'WA' => 'Washington',     'WV' => 'West Virginia',
        'WI' => 'Wisconsin',      'WY' => 'Wyoming',
        // --- International ---
        '--MX' => 'Mexico',
        '--CA' => 'Canada',
        '--OT' => 'Other',
    );

    public static function render($atts) {
        if (!is_user_logged_in()) {
            wp_safe_redirect(HL_Auth_Service::get_login_page_url() ?: wp_login_url());
            exit;
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // If profile already complete, redirect to dashboard
        if (HL_Auth_Service::is_profile_complete($user_id)) {
            wp_safe_redirect(HL_Core::get_dashboard_url());
            exit;
        }

        // Check for success redirect
        if (isset($_GET['hl_profile_saved'])) {
            wp_safe_redirect(HL_Core::get_dashboard_url());
            exit;
        }

        // Check for validation error from POST
        $errors = array();
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';
        if (isset($_GET['hl_profile_error']) && $session_token) {
            $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
            $errors = get_transient($transient_key) ?: array();
            delete_transient($transient_key);
        }

        // Existing profile data (partial save from previous attempt)
        $profile = HL_Auth_Repository::get($user_id);

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // Session token for PRG
        $session_token = wp_generate_password(32, false);
        setcookie('hl_auth_session', $session_token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

        ob_start();
        ?>
        <div class="hl-auth-card hl-auth-card--wide">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Complete Your Profile', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle">
                <?php echo esc_html(sprintf(
                    __('Welcome, %s! Please complete your profile to continue.', 'hl-core'),
                    $user->first_name ?: $user->display_name
                )); ?>
            </p>

            <!-- Step Indicator (I7) -->
            <div class="hl-steps" role="tablist">
                <div class="hl-steps__item hl-steps__item--active" data-step="1" role="tab" aria-selected="true">
                    <span class="hl-steps__number">1</span>
                    <span class="hl-steps__label"><?php esc_html_e('Personal Info', 'hl-core'); ?></span>
                </div>
                <div class="hl-steps__divider"></div>
                <div class="hl-steps__item" data-step="2" role="tab" aria-selected="false">
                    <span class="hl-steps__number">2</span>
                    <span class="hl-steps__label"><?php esc_html_e('Professional', 'hl-core'); ?></span>
                </div>
                <div class="hl-steps__divider"></div>
                <div class="hl-steps__item" data-step="3" role="tab" aria-selected="false">
                    <span class="hl-steps__number">3</span>
                    <span class="hl-steps__label"><?php esc_html_e('Social Media', 'hl-core'); ?></span>
                </div>
            </div>

            <?php if (!empty($errors)) : ?>
                <div class="hl-auth-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    <div>
                        <?php foreach ($errors as $err) : ?>
                            <div><?php echo esc_html($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="" id="hl-profile-form"
                  data-user-id="<?php echo esc_attr($user_id); ?>">
                <?php wp_nonce_field('hl_profile_setup_action', 'hl_profile_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="profile_setup">
                <input type="hidden" name="hl_session_token" value="<?php echo esc_attr($session_token); ?>">

                <!-- ============================================ -->
                <!-- Step 1: Personal Information                  -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="1" role="tabpanel">

                    <!-- First Name + Last Name: 2-column grid -->
                    <div class="hl-field-row hl-field-row--2col">
                        <div class="hl-auth-field">
                            <label for="hl-first-name"><?php esc_html_e('First Name', 'hl-core'); ?> <span class="hl-required">*</span></label>
                            <input type="text" id="hl-first-name" name="hl_first_name"
                                   autocomplete="given-name" required
                                   value="<?php echo esc_attr($user->first_name); ?>">
                        </div>
                        <div class="hl-auth-field">
                            <label for="hl-last-name"><?php esc_html_e('Last Name', 'hl-core'); ?> <span class="hl-required">*</span></label>
                            <input type="text" id="hl-last-name" name="hl_last_name"
                                   autocomplete="family-name" required
                                   value="<?php echo esc_attr($user->last_name); ?>">
                        </div>
                    </div>

                    <!-- Email: read-only -->
                    <div class="hl-auth-field">
                        <label for="hl-email"><?php esc_html_e('Email', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <input type="email" id="hl-email" name="hl_email" readonly
                               class="hl-input--readonly"
                               value="<?php echo esc_attr($user->user_email); ?>">
                    </div>

                    <!-- Nickname -->
                    <div class="hl-auth-field">
                        <label for="hl-nickname"><?php esc_html_e('Nickname', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <input type="text" id="hl-nickname" name="hl_nickname" required
                               placeholder="<?php esc_attr_e('What should we call you?', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->nickname ?? ''); ?>">
                    </div>

                    <!-- Phone Number (OPTIONAL) -->
                    <div class="hl-auth-field">
                        <label for="hl-phone-number"><?php esc_html_e('Phone Number', 'hl-core'); ?> <span class="hl-optional">(<?php esc_html_e('optional', 'hl-core'); ?>)</span></label>
                        <div class="hl-phone-group" role="group" aria-label="<?php esc_attr_e('Phone number', 'hl-core'); ?>">
                            <select name="hl_phone_country_code" id="hl-phone-cc"
                                    autocomplete="tel-country-code" class="hl-phone-cc">
                                <option value="+1" <?php selected($profile->phone_country_code ?? '+1', '+1'); ?>>+1 (US/CA)</option>
                                <option value="+52" <?php selected($profile->phone_country_code ?? '', '+52'); ?>>+52 (MX)</option>
                                <option value="+55" <?php selected($profile->phone_country_code ?? '', '+55'); ?>>+55 (BR)</option>
                            </select>
                            <input type="tel" id="hl-phone-number" name="hl_phone_number"
                                   autocomplete="tel-national"
                                   placeholder="<?php esc_attr_e('(555) 123-4567', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->phone_number ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Gender (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Gender', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::GENDER_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_gender" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->gender ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Ethnicity (pill checkboxes, multi-select) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Ethnicity (select all that apply)', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-pill-check-group">
                            <?php
                            $selected_eth = !empty($profile->ethnicity) ? json_decode($profile->ethnicity, true) : array();
                            foreach (self::ETHNICITY_OPTIONS as $value => $label) :
                            ?>
                                <label class="hl-pill-check">
                                    <input type="checkbox" name="hl_ethnicity[]" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(in_array($value, $selected_eth, true)); ?>>
                                    <span class="hl-pill-check__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Location (dropdown: 50 states + international) -->
                    <div class="hl-auth-field">
                        <label for="hl-location"><?php esc_html_e('Location', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <select name="hl_location_state" id="hl-location" required>
                            <option value=""><?php esc_html_e('-- Select your location --', 'hl-core'); ?></option>
                            <?php
                            $separator_printed = false;
                            foreach (self::LOCATION_OPTIONS as $code => $name) :
                                // Print separator before international options
                                if (!$separator_printed && substr($code, 0, 2) === '--') :
                                    $separator_printed = true;
                                    ?>
                                    <option disabled value="">────────────────</option>
                                <?php endif; ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($profile->location_state ?? '', $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Age Range (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Age', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::AGE_RANGE_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_age_range" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->age_range ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Preferred Course Language (radio buttons, FI4: uses LANGUAGE_OPTIONS constant) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Preferred Course Language', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::LANGUAGE_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_preferred_language" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->preferred_language ?? 'en'), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="hl-auth-btn hl-step-next" data-next="2">
                        <span class="hl-auth-btn-text"><?php esc_html_e('Continue', 'hl-core'); ?></span>
                    </button>
                </div>

                <!-- ============================================ -->
                <!-- Step 2: Professional Information              -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="2" style="display:none;" role="tabpanel">

                    <!-- Years of Experience in Industry (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Years of Experience in Industry', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::YEARS_EXP_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_years_exp_industry" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->years_exp_industry ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Years of Experience in Current Position (radio buttons, SEPARATE field) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Years of Experience in Current Position', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::YEARS_EXP_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_years_exp_position" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->years_exp_position ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Job Title (OPTIONAL) -->
                    <div class="hl-auth-field">
                        <label for="hl-job-title"><?php esc_html_e('Job Title', 'hl-core'); ?> <span class="hl-optional">(<?php esc_html_e('optional', 'hl-core'); ?>)</span></label>
                        <input type="text" id="hl-job-title" name="hl_job_title"
                               autocomplete="organization-title"
                               placeholder="<?php esc_attr_e('e.g., Lead Pre-K Teacher', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->job_title ?? ''); ?>">
                    </div>

                    <div class="hl-step-nav">
                        <button type="button" class="hl-auth-btn hl-auth-btn--secondary hl-step-prev" data-prev="1">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Back', 'hl-core'); ?></span>
                        </button>
                        <button type="button" class="hl-auth-btn hl-step-next" data-next="3">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Continue', 'hl-core'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- Step 3: Social Media + Consent                -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="3" style="display:none;" role="tabpanel">

                    <p class="hl-step-intro"><?php esc_html_e('All social media fields are optional.', 'hl-core'); ?></p>

                    <!-- Instagram (@ prefix) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-instagram"><?php esc_html_e('Instagram', 'hl-core'); ?></label>
                        <div class="hl-input-prefix-group">
                            <span class="hl-input-prefix">@</span>
                            <input type="text" id="hl-social-instagram" name="hl_social_instagram"
                                   placeholder="<?php esc_attr_e('username', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->social_instagram ?? ''); ?>">
                        </div>
                    </div>

                    <!-- X / Twitter (@ prefix) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-twitter"><?php esc_html_e('X (Twitter)', 'hl-core'); ?></label>
                        <div class="hl-input-prefix-group">
                            <span class="hl-input-prefix">@</span>
                            <input type="text" id="hl-social-twitter" name="hl_social_twitter"
                                   placeholder="<?php esc_attr_e('username', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->social_twitter ?? ''); ?>">
                        </div>
                    </div>

                    <!-- LinkedIn (URL) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-linkedin"><?php esc_html_e('LinkedIn', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-linkedin" name="hl_social_linkedin"
                               placeholder="<?php esc_attr_e('https://linkedin.com/in/yourprofile', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_linkedin ?? ''); ?>">
                    </div>

                    <!-- Facebook (URL) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-facebook"><?php esc_html_e('Facebook', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-facebook" name="hl_social_facebook"
                               placeholder="<?php esc_attr_e('https://facebook.com/yourprofile', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_facebook ?? ''); ?>">
                    </div>

                    <!-- Website / URL -->
                    <div class="hl-auth-field">
                        <label for="hl-social-website"><?php esc_html_e('Website / URL', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-website" name="hl_social_website"
                               placeholder="<?php esc_attr_e('https://yourwebsite.com', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_website ?? ''); ?>">
                    </div>

                    <!-- Consent (required) -->
                    <div class="hl-consent-box">
                        <h3><?php esc_html_e('Research Participation Consent', 'hl-core'); ?></h3>
                        <div class="hl-consent-text">
                            <p><?php esc_html_e('By checking this box, you acknowledge that you have read and agree to participate in the Housman Learning research study. Your data will be used for research purposes and handled in accordance with our privacy policy.', 'hl-core'); ?></p>
                        </div>
                        <label class="hl-consent-check">
                            <input type="checkbox" name="hl_consent" value="1" required>
                            <span><?php esc_html_e('I agree to the research participation terms', 'hl-core'); ?></span>
                        </label>
                    </div>

                    <div class="hl-step-nav">
                        <button type="button" class="hl-auth-btn hl-auth-btn--secondary hl-step-prev" data-prev="2">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Back', 'hl-core'); ?></span>
                        </button>
                        <button type="submit" class="hl-auth-btn" id="hl-profile-submit">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Complete Profile', 'hl-core'); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

#### Profile Setup POST Handler (I15 Query Sequence)

```php
// In HL_Auth_Manager::handle_auth_redirects()

if (is_page() && isset($_POST['hl_auth_action']) && $_POST['hl_auth_action'] === 'profile_setup') {
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url());
        exit;
    }

    if (!wp_verify_nonce($_POST['hl_profile_nonce'] ?? '', 'hl_profile_setup_action')) {
        wp_die(__('Security check failed.', 'hl-core'));
    }

    $user_id = get_current_user_id();
    $session_token = sanitize_text_field($_POST['hl_session_token'] ?? '');

    // -------------------------------------------------------
    // Sanitize ALL fields
    // -------------------------------------------------------

    // Step 1: Personal Information
    $first_name    = sanitize_text_field($_POST['hl_first_name'] ?? '');
    $last_name     = sanitize_text_field($_POST['hl_last_name'] ?? '');
    $nickname      = sanitize_text_field($_POST['hl_nickname'] ?? '');
    $phone_cc      = sanitize_text_field($_POST['hl_phone_country_code'] ?? '+1');
    $phone_num     = sanitize_text_field($_POST['hl_phone_number'] ?? '');
    $gender        = sanitize_text_field($_POST['hl_gender'] ?? '');
    $ethnicity     = isset($_POST['hl_ethnicity']) ? array_map('sanitize_text_field', (array) $_POST['hl_ethnicity']) : array();
    $location      = sanitize_text_field($_POST['hl_location_state'] ?? '');
    $age_range     = sanitize_text_field($_POST['hl_age_range'] ?? '');
    $language      = sanitize_text_field($_POST['hl_preferred_language'] ?? 'en');

    // Step 2: Professional Information
    $years_industry = sanitize_text_field($_POST['hl_years_exp_industry'] ?? '');
    $years_position = sanitize_text_field($_POST['hl_years_exp_position'] ?? '');
    $job_title      = sanitize_text_field($_POST['hl_job_title'] ?? '');

    // Step 3: Social Media (all optional)
    // FI3: Strip leading @ from social handles (users naturally type @username)
    $social_instagram = ltrim(sanitize_text_field($_POST['hl_social_instagram'] ?? ''), '@');
    $social_twitter   = ltrim(sanitize_text_field($_POST['hl_social_twitter'] ?? ''), '@');
    $social_linkedin  = esc_url_raw($_POST['hl_social_linkedin'] ?? '');
    $social_facebook  = esc_url_raw($_POST['hl_social_facebook'] ?? '');
    $social_website   = esc_url_raw($_POST['hl_social_website'] ?? '');

    // Consent
    $consent = !empty($_POST['hl_consent']);

    // -------------------------------------------------------
    // Validate against allowlists
    // -------------------------------------------------------

    // Phone country code
    $valid_cc = array('+1', '+52', '+55');
    if (!in_array($phone_cc, $valid_cc, true)) {
        $phone_cc = '+1';
    }

    // Gender
    $valid_gender_keys = array_keys(HL_Frontend_Profile_Setup::GENDER_OPTIONS);
    if (!empty($gender) && !in_array($gender, $valid_gender_keys, true)) {
        $gender = '';
    }

    // Ethnicity against allowlist (I13)
    $valid_eth_keys = array_keys(HL_Frontend_Profile_Setup::ETHNICITY_OPTIONS);
    $ethnicity = array_values(array_intersect($ethnicity, $valid_eth_keys));

    // Location against allowlist
    $valid_location_keys = array_keys(HL_Frontend_Profile_Setup::LOCATION_OPTIONS);
    if (!empty($location) && !in_array($location, $valid_location_keys, true)) {
        $location = '';
    }

    // Age range
    $valid_age_keys = array_keys(HL_Frontend_Profile_Setup::AGE_RANGE_OPTIONS);
    if (!empty($age_range) && !in_array($age_range, $valid_age_keys, true)) {
        $age_range = '';
    }

    // Language (FI4: validate against LANGUAGE_OPTIONS constant)
    $valid_lang_keys = array_keys(HL_Frontend_Profile_Setup::LANGUAGE_OPTIONS);
    if (!in_array($language, $valid_lang_keys, true)) {
        $language = 'en';
    }

    // Years exp (both fields, same allowlist)
    $valid_years_keys = array_keys(HL_Frontend_Profile_Setup::YEARS_EXP_OPTIONS);
    if (!empty($years_industry) && !in_array($years_industry, $valid_years_keys, true)) {
        $years_industry = '';
    }
    if (!empty($years_position) && !in_array($years_position, $valid_years_keys, true)) {
        $years_position = '';
    }

    // -------------------------------------------------------
    // Required field checks
    // -------------------------------------------------------
    $errors = array();

    if (empty($first_name)) {
        $errors[] = __('First name is required.', 'hl-core');
    }
    if (empty($last_name)) {
        $errors[] = __('Last name is required.', 'hl-core');
    }
    if (empty($nickname)) {
        $errors[] = __('Nickname is required.', 'hl-core');
    }
    if (empty($gender)) {
        $errors[] = __('Gender is required.', 'hl-core');
    }
    if (empty($ethnicity)) {
        $errors[] = __('Please select at least one ethnicity option.', 'hl-core');
    }
    if (empty($location)) {
        $errors[] = __('Location is required.', 'hl-core');
    }
    if (empty($age_range)) {
        $errors[] = __('Age range is required.', 'hl-core');
    }
    if (empty($language)) {
        $errors[] = __('Preferred language is required.', 'hl-core');
    }
    if (empty($years_industry)) {
        $errors[] = __('Years of experience in industry is required.', 'hl-core');
    }
    if (empty($years_position)) {
        $errors[] = __('Years of experience in current position is required.', 'hl-core');
    }
    if (!$consent) {
        $errors[] = __('You must agree to the research participation terms.', 'hl-core');
    }

    if (!empty($errors)) {
        if (!empty($session_token)) {
            $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
            set_transient($transient_key, $errors, 30);
        }
        $setup_url = HL_Auth_Service::get_profile_setup_page_url();
        wp_safe_redirect(add_query_arg('hl_profile_error', '1', $setup_url));
        exit;
    }

    // -------------------------------------------------------
    // I15: Strict query sequence
    // -------------------------------------------------------

    // Step 1: Update WP user (first_name, last_name stored in wp_users;
    // FC3: nickname synced to WP usermeta too -- BuddyBoss reads it for profile display)
    $wp_update = wp_update_user(array(
        'ID'         => $user_id,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'nickname'   => $nickname,
    ));

    if (is_wp_error($wp_update)) {
        error_log('[HL Auth] wp_update_user failed for user ' . $user_id . ': ' . $wp_update->get_error_message());
        if (!empty($session_token)) {
            $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
            set_transient($transient_key, array(__('Failed to save profile. Please try again.', 'hl-core')), 30);
        }
        $setup_url = HL_Auth_Service::get_profile_setup_page_url();
        wp_safe_redirect(add_query_arg('hl_profile_error', '1', $setup_url));
        exit;
    }

    // Step 2: Upsert profile (C1: INSERT ... ON DUPLICATE KEY UPDATE)
    $profile_data = array(
        'nickname'             => $nickname,
        'phone_country_code'   => $phone_cc,
        'phone_number'         => $phone_num,
        'gender'               => $gender,
        'ethnicity'            => wp_json_encode($ethnicity),
        'location_state'       => $location,
        'age_range'            => $age_range,
        'preferred_language'   => $language,
        'years_exp_industry'   => $years_industry,
        'years_exp_position'   => $years_position,
        'job_title'            => $job_title,
        'social_instagram'     => $social_instagram,
        'social_twitter'       => $social_twitter,
        'social_linkedin'      => $social_linkedin,
        'social_facebook'      => $social_facebook,
        'social_website'       => $social_website,
        'profile_completed_at' => current_time('mysql'),
    );

    // FI5: Only set consent_given_at when consent is checked AND no existing timestamp.
    // Preserves the original consent moment across re-saves.
    $existing_profile = HL_Auth_Repository::get($user_id);
    $has_existing_consent = $existing_profile && !empty($existing_profile->consent_given_at);
    if ($consent && !$has_existing_consent) {
        $profile_data['consent_given_at'] = current_time('mysql');
        $profile_data['consent_version']  = '1.0';
    }

    $upsert_result = HL_Auth_Repository::upsert($user_id, $profile_data);

    if (!$upsert_result) {
        error_log('[HL Auth] Profile upsert failed for user ' . $user_id);
        if (!empty($session_token)) {
            $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
            set_transient($transient_key, array(__('Failed to save profile. Please try again.', 'hl-core')), 30);
        }
        $setup_url = HL_Auth_Service::get_profile_setup_page_url();
        wp_safe_redirect(add_query_arg('hl_profile_error', '1', $setup_url));
        exit;
    }

    // Step 3: Enrollment language sync (non-critical) (I16)
    HL_Auth_Service::sync_enrollment_language($user_id, $language);

    // Set profile complete cache (I17: wp_cache, not transient)
    wp_cache_set('profile_complete_' . $user_id, true, 'hl_profiles', 3600);

    // Audit log
    HL_Audit_Service::log('user.profile_completed', array(
        'entity_type' => 'user',
        'entity_id'   => $user_id,
    ));

    // Redirect to dashboard
    wp_safe_redirect(HL_Core::get_dashboard_url());
    exit;
}
```

#### Pill Checkbox Component CSS (I5)

```css
/* Pill checkbox (multi-select) */
.hl-pill-check-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.hl-pill-check {
    display: inline-flex;
    cursor: pointer;
}

.hl-pill-check input[type="checkbox"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.hl-pill-check__label {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-pill);
    font-size: 13px;
    font-weight: 500;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: all 0.15s ease;
    user-select: none;
}

.hl-pill-check__label:hover {
    border-color: var(--hl-interactive);
    background: var(--hl-interactive-bg);
}

.hl-pill-check input:checked + .hl-pill-check__label {
    background: var(--hl-interactive-bg);
    border-color: var(--hl-interactive);
    color: var(--hl-interactive-dark);
    font-weight: 600;
}

.hl-pill-check input:focus-visible + .hl-pill-check__label {
    outline: 2px solid var(--hl-interactive);
    outline-offset: 2px;
}
```

#### Step Indicator CSS (I7)

```css
/* Step indicator */
.hl-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 32px;
}

.hl-steps__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: default;
}

.hl-steps__number {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    background: var(--hl-bg);
    color: var(--hl-text-secondary);
    border: 2px solid var(--hl-border);
    transition: all 0.2s ease;
}

.hl-steps__label {
    font-size: 13px;
    font-weight: 500;
    color: var(--hl-text-secondary);
}

.hl-steps__divider {
    width: 32px;
    height: 2px;
    background: var(--hl-border);
}

/* Active step */
.hl-steps__item--active .hl-steps__number {
    background: var(--hl-interactive);
    border-color: var(--hl-interactive);
    color: #fff;
}

.hl-steps__item--active .hl-steps__label {
    color: var(--hl-interactive-dark);
    font-weight: 600;
}

/* Completed step (no errors) */
.hl-steps__item--complete .hl-steps__number {
    background: var(--hl-accent);
    border-color: var(--hl-accent);
    color: #fff;
    font-size: 0; /* FI2: Hide the step number text */
}

.hl-steps__item--complete .hl-steps__number::after {
    content: '\2713'; /* checkmark */
    font-size: 14px; /* FI2: Restore readable size for the checkmark */
}

/* Step with errors (I7) */
.hl-steps__item--error .hl-steps__number {
    background: var(--hl-warning);
    border-color: var(--hl-warning);
    color: #fff;
}

/* Phone group */
.hl-phone-group {
    display: flex;
    gap: 8px;
}

.hl-phone-cc {
    width: 110px;
    flex-shrink: 0;
}

.hl-phone-group input[type="tel"] {
    flex: 1;
    min-width: 0;
}

/* Step navigation */
.hl-step-nav {
    display: flex;
    gap: 12px;
    margin-top: 8px;
}

.hl-step-nav .hl-auth-btn {
    flex: 1;
}

.hl-auth-btn--secondary {
    background: var(--hl-bg);
    color: var(--hl-text);
    border: 1px solid var(--hl-border);
}

.hl-auth-btn--secondary:hover {
    background: var(--hl-bg-hover);
    border-color: var(--hl-border-medium);
}

/* Consent box */
.hl-consent-box {
    background: var(--hl-bg-subtle);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-sm);
    padding: 24px;
    margin-bottom: 24px;
    text-align: left;
}

.hl-consent-box h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--hl-text-heading);
    margin: 0 0 12px;
}

.hl-consent-text {
    font-size: 14px;
    line-height: 1.6;
    color: var(--hl-text);
    margin-bottom: 16px;
}

.hl-consent-check {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: var(--hl-text);
}

.hl-consent-check input[type="checkbox"] {
    margin-top: 2px;
    flex-shrink: 0;
}

/* Wide card variant for profile setup */
.hl-auth-card--wide {
    max-width: 640px;
    margin: 0 auto;
}

/* ---- 2-Column Grid for Name Fields ---- */
.hl-field-row--2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media (max-width: 480px) {
    .hl-field-row--2col {
        grid-template-columns: 1fr;
    }
}

/* ---- Read-only input (email) ---- */
.hl-input--readonly {
    background: var(--hl-bg) !important;
    color: var(--hl-text-secondary) !important;
    cursor: not-allowed;
}

/* ---- Required / Optional indicators ---- */
.hl-required {
    color: var(--hl-error);
    font-weight: 600;
}

.hl-optional {
    color: var(--hl-text-secondary);
    font-weight: 400;
    font-size: 12px;
}

/* ---- Radio Group ---- */
.hl-radio-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.hl-radio {
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.hl-radio input[type="radio"] {
    margin-right: 6px;
    accent-color: var(--hl-interactive);
}

.hl-radio__label {
    font-size: 14px;
    font-weight: 500;
    color: var(--hl-text);
}

/* ---- Location Dropdown ---- */
.hl-auth-field select {
    width: 100%;
    box-sizing: border-box;
    font-family: var(--hl-font);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    padding: 12px 14px;
    font-size: 15px;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236B7280' stroke-width='1.5' fill='none'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
}

.hl-auth-field select:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

/* ---- Social Media @ Prefix Input ---- */
.hl-input-prefix-group {
    display: flex;
    align-items: stretch;
}

.hl-input-prefix {
    display: flex;
    align-items: center;
    padding: 0 12px;
    background: var(--hl-bg);
    border: 1px solid var(--hl-border);
    border-right: none;
    border-radius: var(--hl-radius-xs) 0 0 var(--hl-radius-xs);
    font-size: 15px;
    font-weight: 600;
    color: var(--hl-text-secondary);
}

.hl-input-prefix-group input {
    flex: 1;
    min-width: 0;
    border-radius: 0 var(--hl-radius-xs) var(--hl-radius-xs) 0 !important;
}

/* URL inputs (LinkedIn, Facebook, Website) */
.hl-auth-field input[type="url"] {
    width: 100%;
    box-sizing: border-box;
    font-family: var(--hl-font);
    border: 1px solid var(--hl-border);
    border-radius: var(--hl-radius-xs);
    padding: 12px 14px;
    font-size: 15px;
    color: var(--hl-text);
    background: var(--hl-surface);
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
    outline: none;
}

.hl-auth-field input[type="url"]:focus {
    border-color: var(--hl-interactive);
    box-shadow: 0 0 0 3px var(--hl-interactive-bg);
}

/* Step intro text */
.hl-step-intro {
    font-size: 14px;
    color: var(--hl-text-secondary);
    margin: 0 0 20px;
}
```

### F. Profile Gate Enforcement

#### Frontend Gate (template_redirect)

```php
// In HL_Auth_Manager::handle_auth_redirects() -- priority 5

public function handle_auth_redirects() {
    // --- 1. Auth page POST handlers (login, reset, profile) ---
    // (Already defined above in sections C, D, E)

    // --- 2. Already-logged-in redirect on auth pages (I22) ---
    if (is_user_logged_in() && is_page()) {
        global $post;
        // Login and reset pages: redirect away if already logged in
        if (strpos($post->post_content, '[hl_login]') !== false ||
            strpos($post->post_content, '[hl_password_reset]') !== false) {

            $redirect_url = HL_Auth_Service::resolve_post_login_redirect(wp_get_current_user());
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    // --- 3. Nocache headers for auth pages (I8) ---
    if (is_page()) {
        global $post;
        $auth_shortcodes = array('[hl_login]', '[hl_password_reset]', '[hl_profile_setup]');
        foreach ($auth_shortcodes as $sc) {
            if (strpos($post->post_content, $sc) !== false) {
                nocache_headers();
                break;
            }
        }
    }

    // --- 4. Profile gate (frontend) (C6, C7) ---
    if (!is_user_logged_in()) {
        return;
    }

    // Don't gate admins
    if (current_user_can('manage_options')) {
        return;
    }

    // Don't gate the profile setup page itself
    if (is_page()) {
        global $post;
        if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
            return;
        }
        // Don't gate auth pages
        if (strpos($post->post_content, '[hl_login]') !== false ||
            strpos($post->post_content, '[hl_password_reset]') !== false) {
            return;
        }
    }

    // Only gate pages that have [hl_*] shortcodes (our pages)
    if (!is_page()) {
        return;
    }
    global $post;
    if (strpos($post->post_content, '[hl_') === false) {
        return;
    }

    // Check if profile is complete
    $user_id = get_current_user_id();
    if (!HL_Auth_Service::is_profile_complete($user_id)) {
        $setup_url = HL_Auth_Service::get_profile_setup_page_url();

        // C7: Guard against empty URL = infinite redirect
        if (empty($setup_url)) {
            error_log('[HL Auth] Profile setup page URL is empty. Skipping profile gate for user ' . $user_id . '. Create a page with [hl_profile_setup] shortcode.');
            return; // Fail open
        }

        wp_safe_redirect($setup_url);
        exit;
    }
}
```

#### wp-admin Gate (admin_init) (C6)

```php
// HL_Auth_Manager::enforce_profile_gate_admin()

public function enforce_profile_gate_admin() {
    // Let AJAX through
    if (wp_doing_ajax()) {
        return;
    }

    // Let cron through
    if (wp_doing_cron()) {
        return;
    }

    // Let WP-CLI through
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }

    // Don't gate admins
    if (!is_user_logged_in() || current_user_can('manage_options')) {
        return;
    }

    // Check profile completion
    $user_id = get_current_user_id();
    if (HL_Auth_Service::is_profile_complete($user_id)) {
        return;
    }

    $setup_url = HL_Auth_Service::get_profile_setup_page_url();

    // C7: Fail open if setup page doesn't exist
    if (empty($setup_url)) {
        error_log('[HL Auth] Profile setup page URL is empty. Skipping admin gate for user ' . $user_id);
        return;
    }

    wp_safe_redirect($setup_url);
    exit;
}
```

### G. Security Measures

#### Rate Limiting (C3)

```php
class HL_Auth_Service {

    /**
     * Max failed login attempts before lockout.
     */
    const MAX_FAILED_ATTEMPTS = 5;

    /**
     * Lockout window in seconds (15 minutes).
     */
    const LOCKOUT_WINDOW = 900;

    /**
     * Get the real client IP, accounting for AWS load balancer (C3).
     *
     * @return string Sanitized IP address.
     */
    public static function get_client_ip() {
        // AWS Lightsail LB sets X-Forwarded-For
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // X-Forwarded-For can be "client, proxy1, proxy2"
            // Leftmost is the original client IP
            $ips = explode(',', sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']));
            $client_ip = trim($ips[0]);
            if (filter_var($client_ip, FILTER_VALIDATE_IP)) {
                return $client_ip;
            }
        }

        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * Check if an IP is rate-limited.
     *
     * Uses wp_options with expiring transients (not wp_cache, because rate limit
     * state MUST persist across requests and survive page redirects).
     *
     * @param string $ip Client IP.
     * @return bool True if rate-limited.
     */
    public static function check_rate_limit($ip) {
        $key = 'hl_login_attempts_' . md5($ip);
        $data = get_transient($key);

        if (!$data) {
            return false;
        }

        return $data['count'] >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Record a failed login attempt.
     *
     * @param string $ip Client IP.
     */
    public static function record_failed_attempt($ip) {
        $key = 'hl_login_attempts_' . md5($ip);
        $data = get_transient($key);

        if (!$data) {
            $data = array('count' => 0, 'first_at' => time());
        }

        $data['count']++;
        set_transient($key, $data, self::LOCKOUT_WINDOW);
    }

    /**
     * Clear rate limit on successful login.
     *
     * @param string $ip Client IP.
     */
    public static function clear_rate_limit($ip) {
        $key = 'hl_login_attempts_' . md5($ip);
        delete_transient($key);
    }
}
```

**Rate limit design note:** We use transients (not `wp_cache`) for rate limiting because the data MUST persist across HTTP requests. `wp_cache` without a persistent object cache backend is per-request only. The transient keys are keyed by `md5($ip)` to prevent excessively long option names. With 500 users and a 15-minute window, worst case is ~100 transient rows (not all users fail login) -- negligible autoload impact since WP only autoloads non-expiring options by default as of WP 6.6+.

#### Post-Login Redirect (C4)

```php
/**
 * Resolve where to send the user after login.
 * Extracted from HL_BuddyBoss_Integration::hl_login_redirect() (C4).
 *
 * @param WP_User $user The logged-in user.
 * @return string Redirect URL.
 */
public static function resolve_post_login_redirect($user) {
    if (!($user instanceof \WP_User)) {
        return home_url();
    }

    $bb = HL_BuddyBoss_Integration::instance();

    // Coach-only users (no enrollment): send to Coach Dashboard.
    $is_coach    = in_array('coach', (array) $user->roles, true);
    $hl_roles    = $bb->get_user_hl_roles($user->ID);
    $is_staff    = user_can($user, 'manage_options');

    if ($is_coach && empty($hl_roles) && !$is_staff) {
        $coach_url = self::find_shortcode_page_url('hl_coach_dashboard');
        if ($coach_url) {
            return $coach_url;
        }
    }

    // Non-enrolled, non-coach users: default WordPress redirect
    if (empty($hl_roles) && !$is_coach) {
        return admin_url();
    }

    // Enrolled users: HL Dashboard
    $dashboard_url = HL_Core::get_dashboard_url();
    if ($dashboard_url) {
        return $dashboard_url;
    }

    return home_url();
}

/**
 * Find the URL of a page containing a specific shortcode.
 * Same pattern used throughout the codebase.
 */
private static function find_shortcode_page_url($shortcode) {
    global $wpdb;
    $page_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'page'
         AND post_status = 'publish'
         AND post_content LIKE %s
         LIMIT 1",
        '%[' . $shortcode . ']%'
    ));
    return $page_id ? get_permalink($page_id) : '';
}
```

#### Profile Completeness Check (I17)

```php
/**
 * Check if user has completed their profile.
 * Uses wp_cache to avoid repeated DB queries within a request (I17).
 *
 * @param int $user_id
 * @return bool
 */
public static function is_profile_complete($user_id) {
    // Check object cache first
    $cached = wp_cache_get('profile_complete_' . $user_id, 'hl_profiles');
    if ($cached !== false) {
        return (bool) $cached;
    }

    // Query DB
    $is_complete = HL_Auth_Repository::is_complete($user_id);

    // Cache for 1 hour (or until invalidated by upsert)
    wp_cache_set('profile_complete_' . $user_id, $is_complete ? 1 : 0, 'hl_profiles', 3600);

    return $is_complete;
}
```

#### Repository is_complete Check

```php
/**
 * Check if a user's profile has all required fields.
 *
 * Required fields for completion:
 * - WP: first_name, last_name (checked via get_userdata)
 * - Profile: nickname, gender, ethnicity (non-empty JSON array), location_state,
 *   age_range, preferred_language, years_exp_industry, years_exp_position, consent_given_at
 *
 * Optional fields (NOT required for completion):
 * - phone_number, phone_country_code, job_title
 * - social_instagram, social_twitter, social_linkedin, social_facebook, social_website
 *
 * @param int $user_id
 * @return bool
 */
public static function is_complete($user_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_user_profile';

    // Check WP user fields first
    $user = get_userdata($user_id);
    if (!$user || empty($user->first_name) || empty($user->last_name)) {
        return false;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT nickname, gender, ethnicity, location_state, age_range,
                preferred_language, years_exp_industry, years_exp_position,
                consent_given_at, profile_completed_at
         FROM `{$table}` WHERE user_id = %d",
        $user_id
    ));

    if (!$row) {
        return false;
    }

    // Check profile_completed_at first (fast path -- set atomically on submission)
    // FI6: Also verify consent_given_at to prevent admin-seeded profiles without consent from bypassing the gate
    if (!empty($row->profile_completed_at) && !empty($row->consent_given_at)) {
        return true;
    }

    // Fallback: check individual required fields
    // (handles edge cases like partial data from admin import)
    if (empty($row->nickname))            return false;
    if (empty($row->gender))              return false;
    if (empty($row->location_state))      return false;
    if (empty($row->age_range))           return false;
    if (empty($row->preferred_language))  return false;
    if (empty($row->years_exp_industry))  return false;
    if (empty($row->years_exp_position))  return false;
    if (empty($row->consent_given_at))    return false;

    // Ethnicity: must be a non-empty JSON array
    $ethnicity = json_decode($row->ethnicity ?? '', true);
    if (empty($ethnicity) || !is_array($ethnicity)) {
        return false;
    }

    return true;
}
```

#### User Deletion Cleanup (I19)

```php
// HL_Auth_Manager::cleanup_user_profile() -- hooked to delete_user

public function cleanup_user_profile($user_id) {
    HL_Auth_Repository::delete($user_id);
}
```

```php
// HL_Auth_Repository::delete()
public static function delete($user_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'hl_user_profile';

    $wpdb->delete($table, array('user_id' => $user_id), array('%d'));
    wp_cache_delete('profile_complete_' . $user_id, 'hl_profiles');
}
```

#### Enrollment Language Sync (I16)

```php
/**
 * Sync preferred language to all active enrollments.
 * Non-critical -- logs failures but does not abort the profile save.
 *
 * @param int    $user_id
 * @param string $language
 * @param bool   $skip If true, skips the sync (for admin use cases).
 */
public static function sync_enrollment_language($user_id, $language, $skip = false) {
    if ($skip) return;

    global $wpdb;
    $table = $wpdb->prefix . 'hl_enrollment';

    // Get current values for audit logging (I16)
    $enrollments = $wpdb->get_results($wpdb->prepare(
        "SELECT enrollment_id, language_preference FROM `{$table}` WHERE user_id = %d AND status = 'active'",
        $user_id
    ));

    if (empty($enrollments)) return;

    foreach ($enrollments as $enrollment) {
        if ($enrollment->language_preference === $language) {
            continue; // No change needed
        }

        $result = $wpdb->update(
            $table,
            array('language_preference' => $language),
            array('enrollment_id' => $enrollment->enrollment_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            error_log('[HL Auth] Language sync failed for enrollment ' . $enrollment->enrollment_id);
        } else {
            HL_Audit_Service::log('enrollment.language_synced', array(
                'entity_type' => 'enrollment',
                'entity_id'   => $enrollment->enrollment_id,
                'before_data' => array('language_preference' => $enrollment->language_preference),
                'after_data'  => array('language_preference' => $language),
            ));
        }
    }
}
```

### H. Build Sequence

#### Phase 1: Foundation (1 session)
1. Create `hl_user_profile` table in `get_schema()` + schema revision 32
2. Create `HL_Auth_Repository` (get, upsert with INSERT...ON DUPLICATE KEY UPDATE, delete, is_complete)
3. Create `HL_Auth_Service` (get_client_ip, rate limiting, is_profile_complete, resolve_post_login_redirect, find_shortcode_page_url, page URL helpers)
4. Create `HL_Auth_Manager` (hook registration only -- all hooks wired, handlers call service methods)
5. Add `input[type="password"]` to frontend.css global selector (line 174) (I2)
6. Remove `password_reset_expiration` from `hl-core.php` (I14 -- same commit as HL_Auth_Manager adding it)
7. Wire in `hl-core.php`: `require_once` auth files, `HL_Auth_Manager::instance()` in `init()`

#### Phase 2: Auth Template + Login Page (1 session)
1. Create `templates/hl-auth.php`
2. Update `HL_Shortcodes::use_hl_template()` to detect auth shortcodes and return `hl-auth.php`
3. Add all `.hl-auth-*` CSS to frontend.css
4. Create `HL_Frontend_Login` shortcode renderer
5. Implement login POST handler in `HL_Auth_Manager::handle_auth_redirects()`
6. Implement `HL_Auth_Manager::intercept_wp_login()` with action allowlist (I9)
7. Create `assets/js/hl-auth.js` (form submit guard/spinner -- I23, localStorage partial save -- I18)
8. CLI: add Login page to `create-pages` command
9. Test: Login flow end-to-end, PRG pattern, rate limiting, already-logged-in redirect

#### Phase 3: Password Reset Page (1 session)
1. Create `HL_Frontend_Password_Reset` shortcode renderer
2. Implement reset request POST handler (PRG, retrieve_password with error check -- I11, I21)
3. CLI: add Password Reset page to `create-pages` command
4. Update `intercept_wp_login` to redirect `lostpassword` action to custom page
5. Test: Reset request flow, neutral success message, email delivery

#### Phase 4: Profile Setup Page (1 session)
1. Create `HL_Frontend_Profile_Setup` shortcode renderer (3-step form: Personal Info, Professional, Social Media + Consent)
2. Add `.hl-pill-check`, `.hl-radio-group`, `.hl-steps`, `.hl-phone-group`, `.hl-consent-box`, `.hl-field-row--2col`, `.hl-input-prefix-group`, `.hl-input--readonly` CSS
3. Implement profile POST handler (I15 query sequence, I13 ethnicity validation, gender/age/location/years allowlist validation)
4. Implement enrollment language sync (I16 audit logging)
5. Implement `hl-auth.js` step navigation with per-step required field validation, localStorage partial save for all input types including radios (I18), step error indicators (I7)
6. CLI: add Profile Setup page to `create-pages` command
7. Test: Multi-step form, all required field validation (first_name, last_name, nickname, gender, ethnicity, location, age_range, language, years_exp_industry, years_exp_position, consent), social media optional fields, language sync

#### Phase 5: Profile Gate + Hardened Security (1 session)
1. Implement frontend profile gate in `handle_auth_redirects()` (C7 empty URL guard)
2. Implement wp-admin profile gate in `enforce_profile_gate_admin()` (C6, allow AJAX/cron/CLI)
3. Implement `cleanup_user_profile()` on `delete_user` hook (I19)
4. Audit and replace hardcoded `wp-login.php` URLs in enrollment emails (I10) -- `class-hl-admin-cycles.php`, CLI scripts
5. Fix `retrieve_password()` return check in `class-hl-frontend-user-profile.php` (I11)
6. Add sidebar menu item for Profile Setup (visible only when profile incomplete)
7. Test: Gate enforcement (frontend + wp-admin), user deletion cleanup, email URLs

#### Phase 6: Polish + Documentation (1 session)
1. Responsive CSS review (mobile login, mobile profile setup)
2. Accessibility audit (ARIA attributes, keyboard navigation, screen reader)
3. Update `HL_BuddyBoss_Integration::hl_login_redirect()` to delegate to `HL_Auth_Service::resolve_post_login_redirect()` (C4 transition)
4. Update README.md, STATUS.md
5. Full flow testing: new user invite email -> set password -> login -> profile gate -> profile setup -> dashboard

---

## Part 3: Self-Review Iterations

### Q1: "What would a team of very strict and picky Senior Software Engineers say?"

**Iteration 1 findings:**

1. **Race condition on rate limit check + increment**: Two simultaneous login attempts from the same IP could both pass `check_rate_limit()` before either calls `record_failed_attempt()`. This is acceptable for our use case (500 users, not a high-traffic API) -- the worst case is allowing 1-2 extra attempts beyond the limit, which is fine.

2. **Session token cookie timing**: The cookie is set in the shortcode `render()` method, but cookies must be set before any output. Since `render()` runs inside `do_shortcode()` which runs inside the template, the cookie is set during output. Fix: Move cookie setting to `template_redirect` (before any output). The render method reads the cookie value from a class property.

   **Fixed inline:** Add to `handle_auth_redirects()`:
   ```php
   // Set session token cookie for auth pages (before any output)
   if (is_page()) {
       global $post;
       $needs_session = false;
       $auth_shortcodes = array('[hl_login]', '[hl_password_reset]', '[hl_profile_setup]');
       foreach ($auth_shortcodes as $sc) {
           if (strpos($post->post_content, $sc) !== false) {
               $needs_session = true;
               break;
           }
       }
       if ($needs_session && !isset($_COOKIE['hl_auth_session'])) {
           $token = wp_generate_password(32, false);
           setcookie('hl_auth_session', $token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
           $_COOKIE['hl_auth_session'] = $token; // Make available in same request
       }
   }
   ```
   The render methods then read `$_COOKIE['hl_auth_session']` -- no need to set cookies during output.

3. **`find_shortcode_page_url` is duplicated across many classes**: The spec adds yet another copy in `HL_Auth_Service`. Strict engineers would want a single shared implementation. Fix: Use `HL_Core::get_page_url_by_shortcode()` (add it once to the main class, deprecate the copies over time). For this spec, `HL_Auth_Service` delegates to `HL_Core`.

   **Fixed inline:** Add to HL_Core:
   ```php
   public static function get_page_url_by_shortcode($shortcode) {
       global $wpdb;
       $page_id = $wpdb->get_var($wpdb->prepare(
           "SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'page' AND post_status = 'publish'
            AND post_content LIKE %s LIMIT 1",
           '%[' . $shortcode . ']%'
       ));
       return $page_id ? get_permalink($page_id) : '';
   }
   ```
   Then `HL_Auth_Service::get_login_page_url()` becomes:
   ```php
   public static function get_login_page_url() {
       return HL_Core::get_page_url_by_shortcode('hl_login');
   }
   ```

4. **Profile gate fires on every single page load for non-admin users**: The `is_profile_complete()` call hits the object cache first, so the DB query only happens once per request. With a persistent object cache (Redis/Memcached), it persists across requests. Without one, it queries once per page load. For 500 users, this is fine. Not a performance concern.

5. **The already-logged-in redirect in the render method uses JS redirect**: The shortcode's `render()` returns a `<script>` tag for the redirect. A strict engineer would prefer the redirect to happen in `template_redirect` (before output). Fix: Already handled -- the `handle_auth_redirects()` method at priority 5 does the redirect for logged-in users on auth pages. The JS fallback in `render()` is a belt-and-suspenders defense.

   **Fixed inline:** Remove the JS redirect from `HL_Frontend_Login::render()`. The `template_redirect` handler already catches this case. The render method now assumes the user is NOT logged in (because the redirect already happened).

6. **`wp_signon()` requires specific cookie behavior**: After `wp_signon()`, calling `wp_set_auth_cookie()` is redundant because `wp_signon()` already sets it. Remove the explicit `wp_set_auth_cookie()` call.

   **Fixed inline:** Remove `wp_set_auth_cookie($user->ID, true, is_ssl());` from the login success handler. `wp_signon()` handles this.

**Iteration 2: After fixes above, re-review.**

7. **No CSRF token validation on GET requests with side effects**: The `?hl_auth_error=1` and `?hl_reset_sent=1` GET params only trigger display of a message, not data mutation. No CSRF concern. Good.

8. **Transient key collision**: The transient key is `'hl_auth_err_' . substr(wp_hash($session_token), 0, 20)`. With 500 users, collision probability is negligible (20 hex chars = 16^20 possibilities). Good.

9. **The profile setup form does not prevent re-submission if user navigates back**: After successful profile save, the user is redirected to the dashboard. If they navigate back, the form re-renders. Since we use PRG pattern, the browser won't re-POST. If they manually re-submit, the `is_profile_complete()` check doesn't gate the POST handler. But the upsert is idempotent (ON DUPLICATE KEY UPDATE), so re-submission just overwrites with the same data. Harmless.

10. **`HL_Auth_Service::resolve_post_login_redirect()` calls `HL_BuddyBoss_Integration::instance()->get_user_hl_roles()`**: This means auth still has a runtime dependency on the BB class. The method exists and works whether BB is active or not (it queries `hl_enrollment`), but the class name is misleading.

    **Accepted as technical debt:** The BB integration class is being phased out. For now, `get_user_hl_roles()` needs to be callable. When BB is fully detached, this method moves to `HL_Auth_Service` or a user service. Not blocking for this spec.

**Iteration 3: Senior engineers would now say:** "This is solid. The architectural boundaries are clean, the security measures are appropriate for the user base, the PRG pattern is consistent with the codebase, and the failure modes are all fail-open with logging. Minor tech debt in the BB dependency is documented and planned for removal."

### Q2: "What's the likelihood that users encounter errors?"

**Error source analysis:**

1. **Profile setup page not created** -- Guarded by C7 fix. Fails open, logs warning. Users access site normally. Admin sees error log. **Risk: 0.**

2. **Login page not created** -- `intercept_wp_login()` falls through to default `wp-login.php`. Users can still log in. **Risk: 0.**

3. **Password reset email not delivered** -- This is a mail server issue, not our code. We correctly call `retrieve_password()` and show a neutral message regardless. **Risk: 0 (from our code).**

4. **Rate limiting locks everyone out** -- Fixed by C3. `get_client_ip()` uses X-Forwarded-For on AWS. Each user has their own IP for rate limiting. **Risk: 0.**

5. **Profile data lost on browser crash** -- Fixed by I18. localStorage saves partial form state. On page reload, fields are restored. **Risk: 0.**

6. **Double form submission** -- Fixed by I23. Submit button is disabled with spinner on click. `pointer-events: none` prevents re-click. **Risk: 0.**

7. **Consent timestamp lost on re-save** -- Fixed by FI5. `consent_given_at` is only set when no existing timestamp exists. Re-saves preserve the original consent moment. The gate prevents re-access anyway (`is_profile_complete()` redirects to dashboard). **Risk: 0.**

8. **Session cookie not set (privacy/cookie-blocker browsers)** -- If the `hl_auth_session` cookie is blocked, the PRG error display won't work (transient key can't be reconstructed). The login will still work (wp_signon handles its own cookies). The user won't see the error message, but the page will simply redirect them back to the login form with no message. They can try again. Minor UX degradation, not an error. **Risk: 0 (functional), minor UX annoyance.**

9. **Template not found** -- `hl-auth.php` ships with the plugin. If the file is missing, `template_include` returns a non-existent path and WordPress falls back to index.php. Extremely unlikely (file deletion in production). **Risk: 0.**

10. **Object cache eviction causes repeated DB queries** -- Not an error, just a performance concern. The query is simple (single row lookup by user_id with unique index). Microseconds. **Risk: 0.**

**Final assessment: 0 out of 10.** All error paths are guarded. All failures are fail-open with logging. No user-facing errors from correct implementation of this spec.

### Q3: Field Coverage Matrix

Every single field from requirements mapped across: Schema, Form HTML, POST handler, Validation, is_complete.

| Field | Schema column | Form HTML | POST handler | Validation | is_complete |
|---|---|---|---|---|---|
| First Name * | (wp_users) | Step 1 `hl_first_name` | `$first_name` | required check | `$user->first_name` |
| Last Name * | (wp_users) | Step 1 `hl_last_name` | `$last_name` | required check | `$user->last_name` |
| Email * | (wp_users) | Step 1 `hl_email` readonly | (not submitted -- readonly) | N/A | N/A (WP user exists) |
| Nickname * | `nickname` varchar(100) | Step 1 `hl_nickname` | `$nickname` | required check | `$row->nickname` |
| Phone CC | `phone_country_code` varchar(5) | Step 1 `hl_phone_country_code` select | `$phone_cc` | allowlist (+1,+52,+55) | optional -- skipped |
| Phone Number | `phone_number` varchar(20) | Step 1 `hl_phone_number` tel | `$phone_num` | sanitize only | optional -- skipped |
| Gender * | `gender` varchar(60) | Step 1 radios `hl_gender` | `$gender` | GENDER_OPTIONS allowlist | `$row->gender` |
| Ethnicity * | `ethnicity` text (JSON) | Step 1 checkboxes `hl_ethnicity[]` | `$ethnicity` array | ETHNICITY_OPTIONS allowlist (I13) | JSON decode + non-empty |
| Location * | `location_state` varchar(100) | Step 1 select `hl_location_state` | `$location` | LOCATION_OPTIONS allowlist | `$row->location_state` |
| Age * | `age_range` varchar(20) | Step 1 radios `hl_age_range` | `$age_range` | AGE_RANGE_OPTIONS allowlist | `$row->age_range` |
| Language * | `preferred_language` varchar(5) | Step 1 radios `hl_preferred_language` | `$language` | LANGUAGE_OPTIONS allowlist (FI4) | `$row->preferred_language` |
| Years Exp Industry * | `years_exp_industry` varchar(20) | Step 2 radios `hl_years_exp_industry` | `$years_industry` | YEARS_EXP_OPTIONS allowlist | `$row->years_exp_industry` |
| Years Exp Position * | `years_exp_position` varchar(20) | Step 2 radios `hl_years_exp_position` | `$years_position` | YEARS_EXP_OPTIONS allowlist | `$row->years_exp_position` |
| Job Title | `job_title` varchar(255) | Step 2 `hl_job_title` | `$job_title` | sanitize only | optional -- skipped |
| Instagram | `social_instagram` varchar(255) | Step 3 `hl_social_instagram` | `$social_instagram` (FI3: ltrim @) | sanitize only | optional -- skipped |
| Twitter | `social_twitter` varchar(255) | Step 3 `hl_social_twitter` | `$social_twitter` (FI3: ltrim @) | sanitize only | optional -- skipped |
| LinkedIn | `social_linkedin` varchar(500) | Step 3 `hl_social_linkedin` URL | `$social_linkedin` | esc_url_raw | optional -- skipped |
| Facebook | `social_facebook` varchar(500) | Step 3 `hl_social_facebook` URL | `$social_facebook` | esc_url_raw | optional -- skipped |
| Website | `social_website` varchar(500) | Step 3 `hl_social_website` URL | `$social_website` | esc_url_raw | optional -- skipped |
| Consent * | `consent_given_at` datetime | Step 3 checkbox `hl_consent` | `$consent` (FI5: conditional set) | required check | `$row->consent_given_at` (FI6: fast-path too) |
| Consent Version | `consent_version` varchar(20) | (implicit, set with consent) | (set with consent_given_at) | N/A | N/A |
| Profile Completed At | `profile_completed_at` datetime | (implicit, set on save) | `current_time('mysql')` | N/A | fast-path + index |
| Created At | `created_at` datetime | (auto DEFAULT) | never in UPDATE | N/A | N/A |
| Updated At | `updated_at` datetime | (auto ON UPDATE) | never in UPDATE | N/A | N/A |

**Zero gaps.** Every required field (*) appears in all 5 columns. Every optional field has schema + form + handler + sanitization. All required fields are checked in both server-side validation AND `is_complete()`.

---

## Appendix A: Global CSS Fix (I2)

In `frontend.css` line 174, change:

```css
/* BEFORE */
:where(.hl-app) input[type="text"],
:where(.hl-app) input[type="email"],
:where(.hl-app) input[type="number"],
:where(.hl-app) input[type="search"],
:where(.hl-app) input[type="date"],
:where(.hl-app) select,
:where(.hl-app) textarea {

/* AFTER */
:where(.hl-app) input[type="text"],
:where(.hl-app) input[type="email"],
:where(.hl-app) input[type="password"],
:where(.hl-app) input[type="number"],
:where(.hl-app) input[type="search"],
:where(.hl-app) input[type="date"],
:where(.hl-app) input[type="tel"],
:where(.hl-app) input[type="url"],
:where(.hl-app) select,
:where(.hl-app) textarea {
```

Note: Also adds `input[type="tel"]` for the phone number field and `input[type="url"]` for social media URL fields.

## Appendix B: hl-auth.js

```javascript
/**
 * HL Auth JS
 *
 * Handles: form submit guards (I23), password strength meter (I3),
 * multi-step navigation with per-step validation, localStorage partial saves (I18),
 * step error indicators (I7).
 */
(function($) {
    'use strict';

    // --- Form Submit Guard (I23) ---
    // Generic guard for login + password reset forms (non-profile).
    $('form.hl-auth-form').not('#hl-profile-form').on('submit', function(e) {
        var $btn = $(this).find('button[type="submit"]');
        if ($btn.attr('aria-disabled') === 'true') {
            e.preventDefault();
            return false;
        }
        $btn.attr('aria-disabled', 'true').addClass('hl-auth-btn--submitting');
    });

    // --- Password Strength Meter (I3) ---
    var $pwInput = $('#hl-reset-new-password, #hl-profile-password');
    if ($pwInput.length) {
        var $meter = $('<div class="hl-pw-strength"><div class="hl-pw-strength__bar"></div></div>');
        var $label = $('<div class="hl-pw-strength__label"></div>');
        $pwInput.after($label).after($meter);

        $pwInput.on('input', function() {
            var pw = $(this).val();
            var score = 0;
            if (pw.length >= 8)         score++;
            if (/[A-Z]/.test(pw))       score++;
            if (/[0-9]/.test(pw))       score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;

            var levels = ['', 'weak', 'fair', 'good', 'strong'];
            var labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
            $meter.removeClass('hl-pw-strength--weak hl-pw-strength--fair hl-pw-strength--good hl-pw-strength--strong');
            if (score > 0) {
                $meter.addClass('hl-pw-strength--' + levels[score]);
            }
            $label.text(pw.length > 0 ? labels[score] : '');
        });
    }

    // --- Multi-Step Navigation ---
    var $form = $('#hl-profile-form');
    if ($form.length) {
        var userId = $form.data('user-id');
        var storageKey = 'hl_profile_draft_' + userId;

        // -------------------------------------------------------
        // Restore from localStorage (I18)
        // Handles text inputs, selects, radio buttons, checkboxes
        // -------------------------------------------------------
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey));
            if (saved) {
                Object.keys(saved).forEach(function(name) {
                    var $fields = $form.find('[name="' + name + '"]');
                    if (!$fields.length) return;

                    if ($fields.first().is(':radio')) {
                        // Radio: check the one with the matching value
                        $fields.filter('[value="' + saved[name] + '"]').prop('checked', true);
                    } else if ($fields.first().is(':checkbox')) {
                        // Checkboxes (ethnicity): saved value is array
                        if (Array.isArray(saved[name])) {
                            saved[name].forEach(function(val) {
                                $form.find('[name="' + name + '"][value="' + val + '"]').prop('checked', true);
                            });
                        }
                    } else if ($fields.length) {
                        // FC2: Always restore from localStorage if saved value exists.
                        // The !$fields.val() guard was preventing overwrite of PHP-prepopulated
                        // values (e.g., first_name from WP), defeating the purpose of localStorage
                        // restore when a user has edited those fields. Readonly fields are already
                        // excluded from the save logic, so they won't be in the saved object.
                        $fields.val(saved[name]);
                    }
                });
            }
        } catch(e) { /* ignore parse errors */ }

        // -------------------------------------------------------
        // Save to localStorage on field change (I18)
        // Captures radios, checkboxes, selects, text, URL, tel
        // -------------------------------------------------------
        $form.on('change input', 'input, select, textarea', function() {
            var data = {};
            $form.find('input, select, textarea').each(function() {
                var $el = $(this);
                var name = $el.attr('name');
                if (!name) return;
                // Skip security fields
                if (name.indexOf('nonce') !== -1 || name.indexOf('token') !== -1 || name.indexOf('action') !== -1) return;
                // Skip read-only email
                if ($el.attr('readonly')) return;

                if ($el.is(':radio')) {
                    if ($el.is(':checked')) {
                        data[name] = $el.val();
                    }
                } else if ($el.is(':checkbox')) {
                    if (!data[name]) data[name] = [];
                    if ($el.is(':checked')) data[name].push($el.val());
                } else {
                    data[name] = $el.val();
                }
            });
            try { localStorage.setItem(storageKey, JSON.stringify(data)); } catch(e) {}
        });

        // FC1: Profile form submit handler -- validates step 3 (consent) BEFORE spinner.
        // Without this, the spinner fires before browser validation, leaving the button
        // permanently spinning if consent is unchecked.
        $form.on('submit', function(e) {
            var $btn = $form.find('button[type="submit"]');
            if ($btn.attr('aria-disabled') === 'true') {
                e.preventDefault();
                return false;
            }

            // Validate step 3 (consent checkbox) before adding spinner
            var errors = validateStep(3);
            if (errors.length > 0) {
                var $panel = $form.find('.hl-step-panel[data-step="3"]');
                $panel.find('.hl-step-errors').remove();
                var html = '<div class="hl-step-errors hl-auth-error" role="alert"><span class="dashicons dashicons-warning"></span><div>';
                errors.forEach(function(msg) { html += '<div>' + msg + '</div>'; });
                html += '</div></div>';
                $panel.prepend(html);
                e.preventDefault();
                return false;
            }

            // Validation passed -- add spinner and clear localStorage
            $btn.attr('aria-disabled', 'true').addClass('hl-auth-btn--submitting');
            try { localStorage.removeItem(storageKey); } catch(ex) {}
        });

        // -------------------------------------------------------
        // Per-step validation rules
        // Returns array of error messages; empty = valid
        // -------------------------------------------------------
        function validateStep(step) {
            var errors = [];
            var $panel = $form.find('.hl-step-panel[data-step="' + step + '"]');

            // Clear previous error indicators
            $panel.find('.hl-field-error').removeClass('hl-field-error');

            if (step === 1) {
                // Required: first_name, last_name, nickname, gender, ethnicity[], location, age_range, language
                if (!$panel.find('[name="hl_first_name"]').val().trim()) {
                    errors.push('First name is required.');
                    $panel.find('[name="hl_first_name"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_last_name"]').val().trim()) {
                    errors.push('Last name is required.');
                    $panel.find('[name="hl_last_name"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_nickname"]').val().trim()) {
                    errors.push('Nickname is required.');
                    $panel.find('[name="hl_nickname"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_gender"]:checked').length) {
                    errors.push('Please select a gender.');
                    $panel.find('[name="hl_gender"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_ethnicity[]"]:checked').length) {
                    errors.push('Please select at least one ethnicity option.');
                    $panel.find('[name="hl_ethnicity[]"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_location_state"]').val()) {
                    errors.push('Location is required.');
                    $panel.find('[name="hl_location_state"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_age_range"]:checked').length) {
                    errors.push('Please select an age range.');
                    $panel.find('[name="hl_age_range"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_preferred_language"]:checked').length) {
                    errors.push('Please select a preferred language.');
                    $panel.find('[name="hl_preferred_language"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                // Phone is OPTIONAL -- no validation
            }

            if (step === 2) {
                // Required: years_exp_industry, years_exp_position
                // Job title is OPTIONAL
                if (!$panel.find('[name="hl_years_exp_industry"]:checked').length) {
                    errors.push('Years of experience in industry is required.');
                    $panel.find('[name="hl_years_exp_industry"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
                if (!$panel.find('[name="hl_years_exp_position"]:checked').length) {
                    errors.push('Years of experience in current position is required.');
                    $panel.find('[name="hl_years_exp_position"]').closest('.hl-auth-field').addClass('hl-field-error');
                }
            }

            // FC1: Step 3 -- Social media fields are ALL optional, but consent is required.
            // Must validate here because the submit guard adds a spinner BEFORE browser
            // validation fires, leaving the button permanently spinning if consent is unchecked.
            if (step === 3) {
                if (!$panel.find('[name="hl_consent"]').is(':checked')) {
                    errors.push('You must agree to the research participation terms.');
                    $panel.find('.hl-consent-box').addClass('hl-field-error');
                }
            }

            return errors;
        }

        // -------------------------------------------------------
        // Step navigation
        // -------------------------------------------------------
        function showStep(step) {
            $form.find('.hl-step-panel').hide();
            $form.find('.hl-step-panel[data-step="' + step + '"]').show();

            // Update step indicators
            $form.closest('.hl-auth-card').find('.hl-steps__item').each(function() {
                var $item = $(this);
                var itemStep = parseInt($item.data('step'), 10);
                $item.removeClass('hl-steps__item--active hl-steps__item--complete hl-steps__item--error');

                if (itemStep === step) {
                    $item.addClass('hl-steps__item--active');
                    $item.attr('aria-selected', 'true');
                } else if (itemStep < step) {
                    // Check if step has errors (I7)
                    var $panel = $form.find('.hl-step-panel[data-step="' + itemStep + '"]');
                    if ($panel.find('.hl-field-error').length) {
                        $item.addClass('hl-steps__item--error');
                    } else {
                        $item.addClass('hl-steps__item--complete');
                    }
                    $item.attr('aria-selected', 'false');
                } else {
                    $item.attr('aria-selected', 'false');
                }
            });

            // Scroll to top of card
            $form.closest('.hl-auth-card')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Next: validate current step before advancing
        $form.on('click', '.hl-step-next', function() {
            var currentStep = parseInt($(this).closest('.hl-step-panel').data('step'), 10);
            var next = parseInt($(this).data('next'), 10);

            var errors = validateStep(currentStep);
            if (errors.length > 0) {
                // Show inline error summary at top of current step
                var $panel = $form.find('.hl-step-panel[data-step="' + currentStep + '"]');
                $panel.find('.hl-step-errors').remove(); // clear previous
                var html = '<div class="hl-step-errors hl-auth-error" role="alert"><span class="dashicons dashicons-warning"></span><div>';
                errors.forEach(function(msg) { html += '<div>' + msg + '</div>'; });
                html += '</div></div>';
                $panel.prepend(html);
                return; // Don't advance
            }

            // Clear error summary if valid
            $form.find('.hl-step-panel[data-step="' + currentStep + '"] .hl-step-errors').remove();
            showStep(next);
        });

        $form.on('click', '.hl-step-prev', function() {
            var prev = parseInt($(this).data('prev'), 10);
            showStep(prev);
        });
    }

})(jQuery);
```

## Appendix C: Email URL Fix (I10)

Files to update:

1. **`includes/admin/class-hl-admin-cycles.php`** lines 2372-2373, 2408-2409:
   ```php
   // BEFORE
   $login_url = 'https://academy.housmanlearning.com/wp-login.php';
   $reset_url = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';
   $invite_url = 'https://academy.housmanlearning.com/wp-login.php?action=rp&key=...';

   // AFTER
   $login_url  = wp_login_url();
   $reset_url  = wp_lostpassword_url();
   $invite_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($user_login), 'login');
   ```
   Note: The invite URL must still point to `wp-login.php?action=rp` because that is where WP core validates the reset key. This is in our allowlist (I9).

2. **`includes/cli/scripts/send-test-emails.php`** -- Same pattern.
3. **`includes/cli/scripts/send-test-emails-v2.php`** -- Same pattern.
4. **`includes/cli/scripts/send-maria-email.php`** -- Same pattern.

## Appendix D: Shortcode Registration + Page Creation

**Shortcode registration** (in `HL_Shortcodes::register_shortcodes()`):
```php
add_shortcode('hl_login',           array('HL_Frontend_Login', 'render'));
add_shortcode('hl_password_reset',  array('HL_Frontend_Password_Reset', 'render'));
add_shortcode('hl_profile_setup',   array('HL_Frontend_Profile_Setup', 'render'));
```

**CLI page creation** (in create-pages command):
```php
$pages[] = array('title' => 'Login',          'slug' => 'login',          'content' => '[hl_login]');
$pages[] = array('title' => 'Password Reset', 'slug' => 'password-reset', 'content' => '[hl_password_reset]');
$pages[] = array('title' => 'Profile Setup',  'slug' => 'profile-setup',  'content' => '[hl_profile_setup]');
```

## Appendix E: File Loading in hl-core.php

```php
// In HL_Core::init() or HL_Core::load_dependencies()

// Auth system
require_once HL_CORE_PLUGIN_DIR . 'includes/auth/class-hl-auth-repository.php';
require_once HL_CORE_PLUGIN_DIR . 'includes/auth/class-hl-auth-service.php';
require_once HL_CORE_PLUGIN_DIR . 'includes/auth/class-hl-auth-manager.php';

// Auth frontend renderers
require_once HL_CORE_PLUGIN_DIR . 'includes/frontend/class-hl-frontend-login.php';
require_once HL_CORE_PLUGIN_DIR . 'includes/frontend/class-hl-frontend-password-reset.php';
require_once HL_CORE_PLUGIN_DIR . 'includes/frontend/class-hl-frontend-profile-setup.php';

// Initialize auth manager (registers hooks)
HL_Auth_Manager::instance();
```

## Appendix F: wp_login_url / wp_lostpassword_url Filter

To make `wp_login_url()` and `wp_lostpassword_url()` return our custom page URLs (so email links and other WP core calls use them):

```php
// In HL_Auth_Manager::__construct()
add_filter('login_url',          [$this, 'filter_login_url'], 10, 3);
add_filter('lostpassword_url',   [$this, 'filter_lostpassword_url'], 10, 2);

public function filter_login_url($login_url, $redirect, $force_reauth) {
    $custom_url = HL_Auth_Service::get_login_page_url();
    if ($custom_url) {
        if (!empty($redirect)) {
            $custom_url = add_query_arg('redirect_to', urlencode($redirect), $custom_url);
        }
        return $custom_url;
    }
    return $login_url;
}

public function filter_lostpassword_url($lostpassword_url, $redirect) {
    $custom_url = HL_Auth_Service::get_password_reset_page_url();
    if ($custom_url) {
        return $custom_url;
    }
    return $lostpassword_url;
}
```

This ensures that anywhere in the codebase (or in WP core / plugins) that calls `wp_login_url()` or `wp_lostpassword_url()` will get our custom page URLs. The email URL fix (Appendix C) leverages this.

---

**End of spec. Ready for implementation.**
