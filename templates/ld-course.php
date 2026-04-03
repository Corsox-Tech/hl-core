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

// Dashboard URL for breadcrumb link — first menu item is the logical "home".
$dashboard_url = !empty($menu_items) ? $menu_items[0]['url'] : home_url('/');

// Course breadcrumb: use the course title.
global $post;
$course_title = get_the_title();

// Courses archive link for breadcrumb parent.
$courses_url = get_post_type_archive_link('sfwd-courses');
if (!$courses_url) {
    $courses_url = home_url('/courses/');
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
if ($has_access && function_exists('learndash_get_next_incomplete_step_id')) {
    $next_step = learndash_get_next_incomplete_step_id($user_id, $course_id);
    if ($next_step) {
        $resume_url = get_permalink($next_step);
    }
}
// Fallback: first lesson.
if ($has_access && empty($resume_url) && !empty($lessons)) {
    $resume_url = get_permalink($lessons[0]['post']->ID);
}

// Remove BB's template part override so LD's own clean templates render.
if (class_exists('BuddyBossTheme\LearndashHelper')) {
    remove_filter('learndash_30_get_template_part', array(BuddyBossTheme\LearndashHelper::instance(), 'ld_30_get_template_part'), 10);
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
            <a href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a> &rsaquo;
            <a href="<?php echo esc_url($courses_url); ?>">Courses</a> &rsaquo;
            <span><?php echo esc_html($course_title); ?></span>
        </div>
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
                <a href="<?php echo esc_url(admin_url('profile.php')); ?>" class="hl-topbar__dropdown-item">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php esc_html_e('My Account', 'hl-core'); ?>
                </a>
                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="hl-topbar__dropdown-item">
                    <span class="dashicons dashicons-migrate"></span>
                    <?php esc_html_e('Log Out', 'hl-core'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <nav class="hl-sidebar" id="hl-sidebar">
        <div class="hl-sidebar__brand">
            <?php if ($logo_url) : ?>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="hl-sidebar__logo-link">
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
            <button class="hl-sidebar__collapse-btn" id="hl-sidebar-collapse-btn" type="button" title="Collapse sidebar">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </button>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="hl-sidebar__item">
                <span class="hl-sidebar__icon dashicons dashicons-migrate"></span>
                <span><?php esc_html_e('Log Out', 'hl-core'); ?></span>
            </a>
        </div>
    </nav>
<?php endif; ?>

<main class="hl-app__content">
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
                <span class="hl-course-sidebar__progress-text"><?php echo $pct; ?>% Complete</span>
            </div>
            <?php endif; ?>

            <!-- Action button -->
            <div class="hl-course-sidebar__action">
                <?php if ($has_access && $pct >= 100) : ?>
                    <span class="hl-course-sidebar__badge hl-course-sidebar__badge--complete">Completed</span>
                    <a href="<?php echo esc_url($resume_url); ?>" class="hl-course-sidebar__btn hl-course-sidebar__btn--secondary">Review Course</a>
                <?php elseif ($has_access) : ?>
                    <a href="<?php echo esc_url($resume_url); ?>" class="hl-course-sidebar__btn"><?php echo $pct > 0 ? 'Continue' : 'Start Course'; ?></a>
                <?php else : ?>
                    <span class="hl-course-sidebar__btn hl-course-sidebar__btn--disabled">Not Enrolled</span>
                <?php endif; ?>
            </div>

            <!-- Course includes -->
            <div class="hl-course-sidebar__includes">
                <h4 class="hl-course-sidebar__includes-title">COURSE INCLUDES</h4>
                <ul class="hl-course-sidebar__includes-list">
                    <?php if ($lesson_count > 0) : ?>
                    <li><span class="dashicons dashicons-media-text"></span> <?php echo $lesson_count; ?> Lesson<?php echo $lesson_count !== 1 ? 's' : ''; ?></li>
                    <?php endif; ?>
                    <?php if ($topic_count > 0) : ?>
                    <li><span class="dashicons dashicons-editor-ul"></span> <?php echo $topic_count; ?> Topic<?php echo $topic_count !== 1 ? 's' : ''; ?></li>
                    <?php endif; ?>
                    <?php if ($quiz_count > 0) : ?>
                    <li><span class="dashicons dashicons-forms"></span> <?php echo $quiz_count; ?> Quiz<?php echo $quiz_count !== 1 ? 'zes' : ''; ?></li>
                    <?php endif; ?>
                    <?php if ($has_cert) : ?>
                    <li><span class="dashicons dashicons-awards"></span> Course Certificate</li>
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
