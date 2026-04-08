<?php
/**
 * HL Core — LearnDash Course Template
 *
 * Serves all `sfwd-courses` singular pages.
 * Bypasses the BuddyBoss theme entirely — outputs a clean HTML document
 * with the HL design system shell (sidebar + topbar) and HL-only CSS.
 *
 * Unlike hl-page.php, this template calls wp_head()/wp_footer() because
 * LearnDash + Grassblade xAPI need their scripts to load via standard
 * WP enqueue hooks. All BB + LD CSS is dequeued before wp_head() fires
 * (handled by HL_Shortcodes::dequeue_bb_ld_assets_on_ld_pages() at priority 9999).
 *
 * Content is rendered by LearnDash via the_content() filter, not shortcodes.
 *
 * @package HL_Core
 */
if (!defined('ABSPATH')) exit;

$bb = HL_BuddyBoss_Integration::instance();
$menu_items = $bb->get_menu_items_for_current_user();
$is_logged_in = is_user_logged_in();

// Current URL for active menu highlighting.
$current_url = trailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));

// User info for topbar.
$user = wp_get_current_user();
$display_name = $user->display_name ?: $user->user_login;

// Detect "View As" session (BuddyBoss Members Switching or User Switching plugin).
$old_user        = null;
$switch_back_url = '';
if (class_exists('BP_Core_Members_Switching')) {
    $old_user = BP_Core_Members_Switching::get_old_user();
    if ($old_user) {
        $switch_back_url = BP_Core_Members_Switching::switch_back_url($old_user);
    }
} elseif (function_exists('user_switching_get_old_user')) {
    $old_user = user_switching_get_old_user();
    if ($old_user) {
        $switch_back_url = user_switching_get_switchback_url();
    }
}
$initials = '';
if ($user->first_name) {
    $initials .= strtoupper(substr($user->first_name, 0, 1));
}
if ($user->last_name) {
    $initials .= strtoupper(substr($user->last_name, 0, 1));
}
if (!$initials && $display_name) {
    $initials = strtoupper(substr($display_name, 0, 2));
}

// User avatar URL (BuddyBoss overrides this with its own avatars).
$avatar_url = get_avatar_url($user->ID, ['size' => 32]);

// Site logo from WP customizer (Appearance → Customize → Site Identity → Logo).
$logo_id  = get_theme_mod('custom_logo');
$logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

// Dashboard URL for logo + breadcrumb — the actual Dashboard page, WPML-aware.
$dashboard_url = HL_Core::get_dashboard_url();
$profile_url   = $is_logged_in ? HL_Core::get_profile_url() : '';

// Course breadcrumb: use the course title.
global $post;
$course_title = get_the_title();

// Pathway breadcrumb: resolve from query param, cookie, or enrollment lookup.
$hl_pathway_id    = isset($_GET['hl_pathway']) ? absint($_GET['hl_pathway']) : 0;
$hl_enrollment_id = isset($_GET['hl_enrollment']) ? absint($_GET['hl_enrollment']) : 0;
$pathway_name     = '';
$pathway_url      = '';

// Pathway breadcrumb helper: look up pathway name by ID.
global $wpdb;
$_hl_pw_table = $wpdb->prefix . 'hl_pathway';

// Find the program page URL (page containing [hl_program_page] shortcode).
$_hl_pp_id = $wpdb->get_var(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[hl\_program\_page%' LIMIT 1"
);
if ($_hl_pp_id) {
    $_hl_pp_id = apply_filters('wpml_object_id', $_hl_pp_id, 'page', true);
}
$_hl_pp_url = $_hl_pp_id ? get_permalink($_hl_pp_id) : '';

// 1) Try query params (user navigated from pathway page).
if ($hl_pathway_id && $hl_enrollment_id) {
    $pathway_name = $wpdb->get_var($wpdb->prepare(
        "SELECT pathway_name FROM {$_hl_pw_table} WHERE pathway_id = %d", $hl_pathway_id
    ));
    if ($pathway_name && $_hl_pp_url) {
        $pathway_url = add_query_arg(array('id' => $hl_pathway_id, 'enrollment' => $hl_enrollment_id), $_hl_pp_url);
        // Store in cookie so lesson/topic pages inherit the context.
        setcookie('hl_nav_pathway', wp_json_encode(array(
            'pathway_id'    => $hl_pathway_id,
            'enrollment_id' => $hl_enrollment_id,
            'course_id'     => get_the_ID(),
        )), 0, '/');
    }
}

// 2) Fallback: check cookie (user navigated into a lesson from a course that had context).
if (!$pathway_name && !empty($_COOKIE['hl_nav_pathway'])) {
    $nav = json_decode(wp_unslash($_COOKIE['hl_nav_pathway']), true);
    if (is_array($nav) && !empty($nav['pathway_id']) && !empty($nav['enrollment_id'])) {
        if (empty($nav['course_id']) || (int) $nav['course_id'] === get_the_ID()) {
            $hl_pathway_id    = (int) $nav['pathway_id'];
            $hl_enrollment_id = (int) $nav['enrollment_id'];
            $pathway_name = $wpdb->get_var($wpdb->prepare(
                "SELECT pathway_name FROM {$_hl_pw_table} WHERE pathway_id = %d", $hl_pathway_id
            ));
            if ($pathway_name && $_hl_pp_url) {
                $pathway_url = add_query_arg(array('id' => $hl_pathway_id, 'enrollment' => $hl_enrollment_id), $_hl_pp_url);
            }
        }
    }
}

// =========================================================================
// Course Info Sidebar Data
// =========================================================================
$course_id = get_the_ID();
$user_id   = get_current_user_id();

// Featured image.
$thumbnail = get_the_post_thumbnail($course_id, 'large', array('class' => 'hl-course-sidebar__image'));

// Check enrollment.
$has_access = function_exists('sfwd_lms_has_access') ? sfwd_lms_has_access($course_id, $user_id) : false;

// Progress (only meaningful if enrolled).
$pct       = 0;
$completed = 0;
$total     = 0;
if ($has_access && function_exists('learndash_course_progress')) {
    $progress = learndash_course_progress(array(
        'user_id'   => $user_id,
        'course_id' => $course_id,
        'array'     => true,
    ));
    $pct       = !empty($progress['percentage']) ? (int) $progress['percentage'] : 0;
    $completed = !empty($progress['completed']) ? (int) $progress['completed'] : 0;
    $total     = !empty($progress['total']) ? (int) $progress['total'] : 0;
}

// Content counts.
$lessons      = array();
$lesson_count = 0;
$topic_count  = 0;
$quiz_count   = 0;
if (function_exists('learndash_get_course_lessons_list')) {
    $lessons = learndash_get_course_lessons_list($course_id, $user_id, array('num' => 0));
    $lesson_count = is_array($lessons) ? count($lessons) : 0;
}

// Topics count (sum across all lessons).
if (is_array($lessons) && function_exists('learndash_get_topic_list')) {
    foreach ($lessons as $lesson_item) {
        $topics = learndash_get_topic_list($lesson_item['post']->ID, $course_id);
        if (is_array($topics)) {
            $topic_count += count($topics);
        }
    }
}

// Quizzes.
if (function_exists('learndash_get_course_quiz_list')) {
    $quizzes    = learndash_get_course_quiz_list($course_id);
    $quiz_count = is_array($quizzes) ? count($quizzes) : 0;
}

// Certificate.
$has_cert = false;
if (function_exists('learndash_get_setting')) {
    $cert_id  = learndash_get_setting($course_id, 'certificate');
    $has_cert = !empty($cert_id);
}

// Resume link (next incomplete lesson).
$resume_url = '';
if ($has_access) {
    // 1) LD's built-in next incomplete step.
    if (function_exists('learndash_get_next_incomplete_step_id')) {
        $next_step = learndash_get_next_incomplete_step_id($user_id, $course_id);
        if ($next_step && get_post($next_step)) {
            $resume_url = get_permalink($next_step);
        }
    }
    // 2) Fallback: first lesson (handles both array and object return formats).
    if (empty($resume_url) && !empty($lessons)) {
        $first = $lessons[0];
        $lesson_post = null;
        if (isset($first['post']) && is_object($first['post'])) {
            $lesson_post = $first['post'];
        } elseif (is_object($first) && isset($first->ID)) {
            $lesson_post = $first;
        }
        if ($lesson_post) {
            $resume_url = get_permalink($lesson_post->ID);
        }
    }
    // 3) Final fallback: LD course steps list.
    if (empty($resume_url) && function_exists('learndash_get_course_steps')) {
        $steps = learndash_get_course_steps($course_id);
        if (!empty($steps)) {
            $resume_url = get_permalink($steps[0]);
        }
    }
}

// Remove BB's template part override so LD's own clean templates render.
if (function_exists('buddyboss_theme')) {
    $bb_theme = buddyboss_theme();
    if ($bb_theme && method_exists($bb_theme, 'learndash_helper')) {
        $bb_ld_helper = $bb_theme->learndash_helper();
        if ($bb_ld_helper) {
            remove_filter('learndash_30_get_template_part', array($bb_ld_helper, 'ld_30_get_template_part'), 10);
            remove_filter('learndash_template', array($bb_ld_helper, 'ld_30_template_routes'), 1000);
        }
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($course_title); ?> — Housman Learning</title>
    <?php wp_site_icon(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo esc_url(includes_url('css/dashicons.min.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(HL_CORE_ASSETS_URL . 'css/frontend.css'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>">
    <?php
    // Hide WP admin bar — our topbar replaces it.
    // Must use filter (show_admin_bar() call is too late at template time).
    add_filter('show_admin_bar', '__return_false');
    // wp_head() fires here — LD + Grassblade scripts load.
    // BB + LD CSS already dequeued at priority 9999 by HL_LD_Detach_CSS.
    wp_head();
    ?>
</head>
<body class="hl-app hl-ld-course">
<script>
if(localStorage.getItem('hl-sidebar-collapsed')==='1'){
    document.body.classList.add('hl-sidebar-is-collapsed');
}
</script>

<?php if ($is_logged_in && !empty($menu_items)) : ?>
    <!-- Top Bar -->
    <div class="hl-topbar<?php echo $old_user ? ' hl-topbar--view-as' : ''; ?>" id="hl-topbar">
        <div class="hl-breadcrumb">
            <a href="<?php echo esc_url($dashboard_url); ?>"><?php esc_html_e('Dashboard', 'hl-core'); ?></a> &rsaquo;
            <?php if ($pathway_name && $pathway_url) : ?>
                <a href="<?php echo esc_url($pathway_url); ?>"><?php echo esc_html($pathway_name); ?></a> &rsaquo;
            <?php endif; ?>
            <span><?php echo esc_html($course_title); ?></span>
        </div>
        <div class="hl-topbar__actions">
            <?php HL_Core::render_language_switcher(); ?>
            <div class="hl-topbar__user-wrap" id="hl-topbar-user-wrap">
                <button class="hl-topbar__user-btn" id="hl-topbar-user-btn" type="button" aria-expanded="false">
                    <span class="hl-topbar__user-name"><?php echo esc_html($display_name); ?></span>
                    <?php if ($avatar_url) : ?>
                        <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="hl-topbar__avatar">
                    <?php else : ?>
                        <div class="hl-topbar__avatar hl-topbar__avatar--initials"><?php echo esc_html($initials); ?></div>
                    <?php endif; ?>
                </button>
                <div class="hl-topbar__dropdown" id="hl-topbar-dropdown" hidden>
                    <?php if ($old_user && $switch_back_url) : ?>
                        <div class="hl-topbar__dropdown-notice">
                            <?php echo esc_html(sprintf(__('Viewing as %s', 'hl-core'), $display_name)); ?>
                        </div>
                        <a href="<?php echo esc_url($switch_back_url); ?>" class="hl-topbar__dropdown-item hl-topbar__dropdown-item--switch-back">
                            <span class="dashicons dashicons-undo"></span>
                            <?php echo esc_html(sprintf(__('Return to %s', 'hl-core'), $old_user->display_name)); ?>
                        </a>
                        <div class="hl-topbar__dropdown-divider"></div>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($profile_url ?: admin_url('profile.php')); ?>" class="hl-topbar__dropdown-item">
                        <span class="dashicons dashicons-admin-users"></span>
                        <?php esc_html_e('My Profile', 'hl-core'); ?>
                    </a>
                    <a href="<?php echo esc_url(wp_logout_url($dashboard_url)); ?>" class="hl-topbar__dropdown-item">
                        <span class="dashicons dashicons-migrate"></span>
                        <?php esc_html_e('Log Out', 'hl-core'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <nav class="hl-sidebar" id="hl-sidebar">
        <div class="hl-sidebar__brand">
            <?php if ($logo_url) : ?>
                <a href="<?php echo esc_url($dashboard_url); ?>" class="hl-sidebar__logo-link">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-sidebar__logo-img">
                </a>
            <?php else : ?>
                <div class="hl-sidebar__logo">HL</div>
                <div class="hl-sidebar__title"><?php esc_html_e('Housman Learning', 'hl-core'); ?></div>
                <div class="hl-sidebar__subtitle"><?php esc_html_e('Learning Hub', 'hl-core'); ?></div>
            <?php endif; ?>
        </div>
        <div class="hl-sidebar__nav">
            <?php foreach ($menu_items as $item) :
                $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
                $is_active = ($item_path && $item_path === $current_url);
                $active_class = $is_active ? ' hl-sidebar__item--active' : '';
            ?>
                <a href="<?php echo esc_url($item['url']); ?>" class="hl-sidebar__item<?php echo esc_attr($active_class); ?>" data-tooltip="<?php echo esc_attr($item['label']); ?>">
                    <span class="hl-sidebar__icon dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                    <span><?php echo esc_html($item['label']); ?></span>
                    <?php if (!empty($item['badge'])) : ?>
                        <span class="hl-sidebar__badge"><?php echo (int) $item['badge']; ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="hl-sidebar__footer">
            <button class="hl-sidebar__collapse-btn" id="hl-sidebar-collapse-btn" type="button" title="<?php esc_attr_e('Collapse sidebar', 'hl-core'); ?>">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </button>
            <a href="<?php echo esc_url(wp_logout_url($dashboard_url)); ?>" class="hl-sidebar__item">
                <span class="hl-sidebar__icon dashicons dashicons-migrate"></span>
                <span><?php esc_html_e('Log Out', 'hl-core'); ?></span>
            </a>
        </div>
    </nav>
<?php endif; ?>

<main class="hl-app__content">
    <!-- Course Hero Banner -->
    <div class="hl-page-hero hl-course-hero">
        <div class="hl-page-hero__icon">
            <span class="dashicons dashicons-welcome-learn-more"></span>
        </div>
        <div class="hl-page-hero__text">
            <?php if ($has_access && $total > 0) : ?>
                <span class="hl-page-hero__tag"><?php printf(esc_html__('%d%% Complete', 'hl-core'), $pct); ?></span>
            <?php elseif ($has_access) : ?>
                <span class="hl-page-hero__tag"><?php esc_html_e('Enrolled', 'hl-core'); ?></span>
            <?php endif; ?>
            <h1 class="hl-page-hero__title"><?php echo esc_html($course_title); ?></h1>
            <?php if ($lesson_count > 0 || $quiz_count > 0) : ?>
                <p class="hl-page-hero__subtitle">
                    <?php
                    $parts = array();
                    if ($lesson_count > 0) $parts[] = sprintf(_n('%d Lesson', '%d Lessons', $lesson_count, 'hl-core'), $lesson_count);
                    if ($topic_count > 0)  $parts[] = sprintf(_n('%d Topic', '%d Topics', $topic_count, 'hl-core'), $topic_count);
                    if ($quiz_count > 0)   $parts[] = sprintf(_n('%d Quiz', '%d Quizzes', $quiz_count, 'hl-core'), $quiz_count);
                    echo esc_html(implode(' &middot; ', $parts));
                    ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="hl-course-layout">
        <!-- Main Course Content -->
        <div class="hl-course-content">
            <?php
            // LearnDash hooks into the_content filter to render all course markup
            // (progress bar, lesson list, tabs, etc.) inside div.learndash-wrapper.
            if (have_posts()) :
                while (have_posts()) : the_post();
                    the_content();
                endwhile;
            endif;
            ?>
        </div>

        <!-- Course Info Sidebar -->
        <aside class="hl-course-sidebar">
            <?php if ($thumbnail) : ?>
            <!-- Course image -->
            <div class="hl-course-sidebar__image-wrap">
                <?php echo $thumbnail; ?>
            </div>
            <?php endif; ?>

            <?php if ($has_access && $total > 0) : ?>
            <!-- Progress -->
            <div class="hl-course-sidebar__progress">
                <div class="hl-course-sidebar__progress-bar">
                    <div class="hl-course-sidebar__progress-fill" style="width: <?php echo $pct; ?>%"></div>
                </div>
                <span class="hl-course-sidebar__progress-text"><?php printf(esc_html__('%d%% Complete', 'hl-core'), $pct); ?></span>
            </div>
            <?php endif; ?>

            <!-- Action button -->
            <div class="hl-course-sidebar__action">
                <?php if ($has_access && $pct >= 100) : ?>
                    <span class="hl-course-sidebar__badge hl-course-sidebar__badge--complete"><?php esc_html_e('Completed', 'hl-core'); ?></span>
                    <?php if ($resume_url) : ?>
                        <a href="<?php echo esc_url($resume_url); ?>" class="hl-course-sidebar__btn hl-course-sidebar__btn--secondary"><?php esc_html_e('Review Course', 'hl-core'); ?></a>
                    <?php endif; ?>
                <?php elseif ($has_access && $resume_url) : ?>
                    <a href="<?php echo esc_url($resume_url); ?>" class="hl-course-sidebar__btn"><?php echo $pct > 0 ? esc_html__('Continue', 'hl-core') : esc_html__('Start Course', 'hl-core'); ?></a>
                <?php elseif ($has_access) : ?>
                    <span class="hl-course-sidebar__badge" style="display:block;text-align:center;padding:10px;background:#e3f2fd;color:#1565c0;border-radius:var(--hl-radius);font-weight:600;font-size:14px;"><?php esc_html_e('Enrolled', 'hl-core'); ?></span>
                <?php else : ?>
                    <span class="hl-course-sidebar__btn hl-course-sidebar__btn--disabled"><?php esc_html_e('Not Enrolled', 'hl-core'); ?></span>
                <?php endif; ?>
            </div>

            <!-- Course includes -->
            <div class="hl-course-sidebar__includes">
                <h4 class="hl-course-sidebar__includes-title"><?php esc_html_e('COURSE INCLUDES', 'hl-core'); ?></h4>
                <ul class="hl-course-sidebar__includes-list">
                    <?php if ($lesson_count > 0) : ?>
                    <li><span class="dashicons dashicons-media-text"></span> <?php printf(_n('%d Lesson', '%d Lessons', $lesson_count, 'hl-core'), $lesson_count); ?></li>
                    <?php endif; ?>
                    <?php if ($topic_count > 0) : ?>
                    <li><span class="dashicons dashicons-editor-ul"></span> <?php printf(_n('%d Topic', '%d Topics', $topic_count, 'hl-core'), $topic_count); ?></li>
                    <?php endif; ?>
                    <?php if ($quiz_count > 0) : ?>
                    <li><span class="dashicons dashicons-forms"></span> <?php printf(_n('%d Quiz', '%d Quizzes', $quiz_count, 'hl-core'), $quiz_count); ?></li>
                    <?php endif; ?>
                    <?php if ($has_cert) : ?>
                    <li><span class="dashicons dashicons-awards"></span> <?php esc_html_e('Course Certificate', 'hl-core'); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </aside>
    </div>
</main>

<?php
// wp_footer() fires here — Grassblade SCORM tracking, LD video JS, etc.
wp_footer();
?>
<script src="<?php echo esc_url(HL_CORE_ASSETS_URL . 'js/frontend.js'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>"></script>
</body>
</html>
