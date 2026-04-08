<?php
/**
 * HL Core — LearnDash Lesson Template
 *
 * Serves all `sfwd-lessons` singular pages.
 * Bypasses the BuddyBoss theme entirely — outputs a clean HTML document
 * with the HL design system shell (sidebar + topbar), a course outline
 * panel, and the lesson content area.
 *
 * 3-column layout: HL Sidebar (collapsible) | Course Outline (collapsible) | Lesson Content.
 *
 * Calls wp_head()/wp_footer() because LearnDash + Grassblade xAPI need
 * their scripts to load via standard WP enqueue hooks. All BB + LD CSS
 * is dequeued before wp_head() fires (handled by
 * HL_Shortcodes::dequeue_bb_ld_assets_on_ld_pages() at priority 9999).
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

// =====================================================
// LESSON & COURSE DATA
// =====================================================
global $post;

$current_lesson_id = $post->ID;
$lesson_title      = get_the_title();

// Parent course.
$course_id    = learndash_get_course_id($post->ID);
$course_title = get_the_title($course_id);
$course_url   = get_permalink($course_id);

// Pathway breadcrumb: resolve from query param or cookie.
$hl_pathway_id    = isset($_GET['hl_pathway']) ? absint($_GET['hl_pathway']) : 0;
$hl_enrollment_id = isset($_GET['hl_enrollment']) ? absint($_GET['hl_enrollment']) : 0;
$pathway_name     = '';
$pathway_url      = '';

global $wpdb;
$_hl_pw_table = $wpdb->prefix . 'hl_pathway';

// Find the program page URL.
$_hl_pp_id = $wpdb->get_var(
    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE '%[hl\_program\_page%' LIMIT 1"
);
if ($_hl_pp_id) {
    $_hl_pp_id = apply_filters('wpml_object_id', $_hl_pp_id, 'page', true);
}
$_hl_pp_url = $_hl_pp_id ? get_permalink($_hl_pp_id) : '';

// 1) Try query params.
if ($hl_pathway_id && $hl_enrollment_id) {
    $pathway_name = $wpdb->get_var($wpdb->prepare(
        "SELECT pathway_name FROM {$_hl_pw_table} WHERE pathway_id = %d", $hl_pathway_id
    ));
    if ($pathway_name && $_hl_pp_url) {
        $pathway_url = add_query_arg(array('id' => $hl_pathway_id, 'enrollment' => $hl_enrollment_id), $_hl_pp_url);
        setcookie('hl_nav_pathway', wp_json_encode(array(
            'pathway_id'    => $hl_pathway_id,
            'enrollment_id' => $hl_enrollment_id,
            'course_id'     => $course_id,
        )), 0, '/');
    }
}

// 2) Fallback: check cookie.
if (!$pathway_name && !empty($_COOKIE['hl_nav_pathway'])) {
    $nav = json_decode(wp_unslash($_COOKIE['hl_nav_pathway']), true);
    if (is_array($nav) && !empty($nav['pathway_id']) && !empty($nav['enrollment_id'])) {
        if (empty($nav['course_id']) || (int) $nav['course_id'] === $course_id) {
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

// All lessons in this course (full list, no pagination).
$lessons = learndash_get_course_lessons_list($course_id, get_current_user_id(), array('num' => 0));

// Section headers (module names) keyed by order number.
$sections = learndash_30_get_course_sections($course_id);

// Course progress for current user.
$progress        = learndash_course_progress(array(
    'user_id'   => get_current_user_id(),
    'course_id' => $course_id,
    'array'     => true,
));
$completion_pct  = !empty($progress['percentage']) ? (int) $progress['percentage'] : 0;
$completed_count = !empty($progress['completed']) ? (int) $progress['completed'] : 0;
$total_count     = !empty($progress['total']) ? (int) $progress['total'] : 0;

// Last activity date.
$activity = learndash_get_user_activity(array(
    'user_id'       => get_current_user_id(),
    'course_id'     => $course_id,
    'activity_type' => 'course',
));
$last_activity = !empty($activity->activity_updated)
    ? date_i18n(get_option('date_format') . ' g:i a', $activity->activity_updated)
    : '';

// Current lesson position + prev/next navigation.
// Re-index to 0-based — LD may return non-sequential keys.
$lessons = array_values($lessons);
$lesson_position = 0;
$total_lessons   = count($lessons);
foreach ($lessons as $i => $lesson) {
    if ($lesson['post']->ID === $current_lesson_id) {
        $lesson_position = $i + 1;
        break;
    }
}
$prev_lesson = ($lesson_position > 1) ? $lessons[$lesson_position - 2] : null;
$next_lesson = ($lesson_position < $total_lessons) ? $lessons[$lesson_position] : null;

// Is current lesson complete?
$is_current_complete = learndash_is_lesson_complete($user->ID, $current_lesson_id, $course_id);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($lesson_title); ?> — <?php echo esc_html($course_title); ?> — Housman Learning</title>
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
<body class="hl-app hl-ld-lesson">
<script>
// Auto-collapse sidebar on lesson pages for maximum content space
document.body.classList.add('hl-sidebar-is-collapsed');
if(localStorage.getItem('hl-course-outline-collapsed')==='1'){
    document.body.classList.add('hl-course-outline-is-collapsed');
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
            <a href="<?php echo esc_url($course_url); ?>"><?php echo esc_html($course_title); ?></a> &rsaquo;
            <span><?php echo esc_html($lesson_title); ?></span>
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

<!-- Course Outline Reopen Button (visible when outline is collapsed) -->
<button class="hl-course-outline__reopen" id="hl-course-outline-reopen" type="button" title="<?php esc_attr_e('Show course outline', 'hl-core'); ?>">
    <span class="dashicons dashicons-list-view"></span>
</button>

<!-- Course Outline Panel -->
<aside class="hl-course-outline" id="hl-course-outline">
    <button class="hl-course-outline__toggle" id="hl-course-outline-toggle" type="button" title="<?php esc_attr_e('Hide course outline', 'hl-core'); ?>">
        <span class="dashicons dashicons-no-alt"></span>
    </button>

    <a href="<?php echo esc_url($course_url); ?>" class="hl-course-outline__back">
        <span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e('Back to Course', 'hl-core'); ?>
    </a>

    <h2 class="hl-course-outline__title"><?php echo esc_html($course_title); ?></h2>

    <div class="hl-course-outline__progress">
        <div class="hl-course-outline__progress-bar">
            <div class="hl-course-outline__progress-fill" style="width: <?php echo (int) $completion_pct; ?>%"></div>
        </div>
        <span class="hl-course-outline__progress-text"><?php printf(esc_html__('%d%% Complete', 'hl-core'), (int) $completion_pct); ?></span>
        <?php if ($last_activity) : ?>
            <span class="hl-course-outline__activity"><?php printf(esc_html__('Last activity on %s', 'hl-core'), esc_html($last_activity)); ?></span>
        <?php endif; ?>
    </div>

    <div class="hl-course-outline__lessons">
        <?php foreach ($lessons as $i => $lesson_item) :
            $l_id    = $lesson_item['post']->ID;
            $l_title = $lesson_item['post']->post_title;
            $l_url   = get_permalink($l_id);
            $l_done  = learndash_is_lesson_complete(get_current_user_id(), $l_id, $course_id);
            $is_current = ($l_id === $current_lesson_id);

            // Check if there's a section header at this position.
            $order_key = $i + 1; // Sections are keyed by 1-based order.
            if (!empty($sections) && isset($sections[$order_key])) :
        ?>
            <div class="hl-course-outline__section">
                <div class="hl-course-outline__section-bar"></div>
                <span class="hl-course-outline__section-title"><?php echo esc_html($sections[$order_key]->post_title); ?></span>
            </div>
        <?php endif; ?>

            <a href="<?php echo esc_url($l_url); ?>"
               class="hl-course-outline__lesson<?php echo $is_current ? ' hl-course-outline__lesson--current' : ''; ?>">
                <span class="hl-course-outline__check<?php echo $l_done ? ' hl-course-outline__check--done' : ''; ?>">
                    <?php if ($l_done) : ?>
                        <span class="dashicons dashicons-yes"></span>
                    <?php endif; ?>
                </span>
                <span class="hl-course-outline__lesson-title"><?php echo esc_html($l_title); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</aside>

<!-- Main Content -->
<main class="hl-ld-content">
    <div class="hl-ld-content__header">
        <span class="hl-ld-content__counter"><?php printf(esc_html__('Lesson %1$d of %2$d', 'hl-core'), (int) $lesson_position, (int) $total_lessons); ?></span>
        <?php if ($is_current_complete) : ?>
            <span class="hl-ld-content__badge hl-ld-content__badge--complete"><?php esc_html_e('Completed', 'hl-core'); ?></span>
        <?php else : ?>
            <span class="hl-ld-content__badge hl-ld-content__badge--progress"><?php esc_html_e('In Progress', 'hl-core'); ?></span>
        <?php endif; ?>
        <div class="hl-ld-content__nav">
            <?php if ($prev_lesson) : ?>
                <a href="<?php echo esc_url(get_permalink($prev_lesson['post']->ID)); ?>" class="hl-ld-content__nav-btn" title="<?php esc_attr_e('Previous lesson', 'hl-core'); ?>">&#8249;</a>
            <?php else : ?>
                <span class="hl-ld-content__nav-btn hl-ld-content__nav-btn--disabled">&#8249;</span>
            <?php endif; ?>
            <?php if ($next_lesson) : ?>
                <a href="<?php echo esc_url(get_permalink($next_lesson['post']->ID)); ?>" class="hl-ld-content__nav-btn" title="<?php esc_attr_e('Next lesson', 'hl-core'); ?>">&#8250;</a>
            <?php else : ?>
                <span class="hl-ld-content__nav-btn hl-ld-content__nav-btn--disabled">&#8250;</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="hl-ld-content__body">
        <?php
        // Remove BB's template part override so LD's own clean templates render.
        if (function_exists('buddyboss_theme')) {
            $bb_theme_obj = buddyboss_theme();
            if ($bb_theme_obj && method_exists($bb_theme_obj, 'learndash_helper')) {
                $bb_ld = $bb_theme_obj->learndash_helper();
                if ($bb_ld) {
                    remove_filter('learndash_30_get_template_part', array($bb_ld, 'ld_30_get_template_part'), 10);
                    remove_filter('learndash_template', array($bb_ld, 'ld_30_template_routes'), 1000);
                }
            }
        }

        // Disable LD's Focus Mode — we have our own course outline panel.
        add_filter('learndash_30_focus_mode', '__return_false');

        // LearnDash hooks into the_content filter to render lesson markup
        // (video, assignments, mark-complete button, etc.).
        if (have_posts()) :
            while (have_posts()) : the_post();
                the_content();
            endwhile;
        endif;
        ?>
    </div>
</main>

<?php
// wp_footer() fires here — Grassblade SCORM tracking, LD video JS, etc.
wp_footer();
?>
<script src="<?php echo esc_url(HL_CORE_ASSETS_URL . 'js/frontend.js'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>"></script>
<script>
(function() {
    'use strict';

    // NOTE: Sidebar toggle and course outline close/open are handled by
    // frontend.js (the IIFE blocks at the bottom). Do NOT duplicate those
    // handlers here — double-binding causes the body class to toggle twice
    // (cancelling itself out) while the element class only toggles once.

    // === Course Outline Reopen ===
    // frontend.js doesn't know about this lesson-only button, so we handle it here.
    var outlineReopen = document.getElementById('hl-course-outline-reopen');
    if (outlineReopen) {
        outlineReopen.addEventListener('click', function() {
            var outline = document.getElementById('hl-course-outline');
            if (outline) outline.classList.remove('hl-course-outline--collapsed');
            document.body.classList.remove('hl-course-outline-is-collapsed');
            localStorage.setItem('hl-course-outline-collapsed', '0');
        });
    }

    // === Scroll current lesson into view in outline ===
    var currentLesson = document.querySelector('.hl-course-outline__lesson--current');
    if (currentLesson) {
        var container = document.querySelector('.hl-course-outline__lessons');
        if (container) {
            // Use a small timeout to ensure layout is complete.
            setTimeout(function() {
                currentLesson.scrollIntoView({ block: 'center', behavior: 'auto' });
            }, 100);
        }
    }
})();
</script>
</body>
</html>
