<?php
/**
 * HL Core Page Template
 *
 * Serves all pages containing [hl_*] shortcodes.
 * Bypasses the active theme entirely — outputs a clean HTML document
 * with only HL Core assets. No wp_head()/wp_footer().
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

// Breadcrumb: find current page label from menu items.
$current_page_label = '';
foreach ($menu_items as $item) {
    $item_path = trailingslashit(wp_parse_url($item['url'], PHP_URL_PATH) ?: '');
    if ($item_path && $item_path === $current_url) {
        $current_page_label = $item['label'];
        break;
    }
}
// Dashboard URL for breadcrumb link — first menu item is the logical "home".
$dashboard_url = !empty($menu_items) ? $menu_items[0]['url'] : home_url('/');

// Element Picker mode — inject picker script, skip tour loading.
$has_picker_param = isset( $_GET['hl_picker'] ) && $_GET['hl_picker'] === '1';
$is_picker_mode   = $has_picker_param && current_user_can( 'manage_hl_core' );
if ( $is_picker_mode ) {
    if ( ! empty( $_GET['hl_view_as'] ) ) {
        $view_as_candidate = sanitize_text_field( $_GET['hl_view_as'] );
        $valid_view_as_roles = array( 'teacher', 'mentor', 'school_leader', 'district_leader', 'coach' );
        if ( in_array( $view_as_candidate, $valid_view_as_roles, true ) ) {
            $GLOBALS['hl_view_as_role'] = $view_as_candidate;
        }
    }
}

// Render shortcode content (may enqueue per-shortcode assets).
global $post;
$page_content = do_shortcode($post->post_content);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html(get_the_title()); ?> — Housman Learning</title>
    <?php wp_site_icon(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo esc_url(includes_url('css/dashicons.min.css')); ?>"><?php // Direct link — more reliable than wp_print_styles in a custom template that bypasses wp_head(). ?>
    <link rel="stylesheet" href="<?php echo esc_url(HL_CORE_ASSETS_URL . 'css/frontend.css'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>">
    <?php
    // Flush any styles enqueued during do_shortcode() (e.g. hl_docs).
    wp_print_styles();
    ?>
    <?php wp_print_scripts(['jquery']); ?>
    <script>
        var hlCoreAjax = { ajaxurl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>' };
    </script>
</head>
<body class="hl-app">
<script>
if(localStorage.getItem('hl-sidebar-collapsed')==='1'){
    document.body.classList.add('hl-sidebar-is-collapsed');
}
</script>

<?php if ($is_logged_in && !empty($menu_items)) : ?>
    <!-- Top Bar -->
    <div class="hl-topbar<?php echo $old_user ? ' hl-topbar--view-as' : ''; ?>" id="hl-topbar">
        <div class="hl-breadcrumb">
            <?php if ($current_page_label) : ?>
                <a href="<?php echo esc_url($dashboard_url); ?>"><?php esc_html_e('Dashboard', 'hl-core'); ?></a> &rsaquo;
                <span><?php echo esc_html($current_page_label); ?></span>
            <?php else : ?>
                <span><?php esc_html_e('Dashboard', 'hl-core'); ?></span>
            <?php endif; ?>
        </div>
        <?php if ( is_user_logged_in() ) : ?>
        <div class="hl-topbar__tour-wrap">
            <button id="hl-tour-trigger" class="hl-topbar__tour-btn" aria-expanded="false" aria-label="<?php esc_attr_e( 'Guided Tours', 'hl-core' ); ?>" title="<?php esc_attr_e( 'Guided Tours', 'hl-core' ); ?>">
                <span class="dashicons dashicons-editor-help"></span>
            </button>
            <div id="hl-tour-dropdown" class="hl-topbar__tour-dropdown" hidden>
                <div class="hl-tour-dropdown__header"><?php _e( 'Guided Tours', 'hl-core' ); ?></div>
                <ul class="hl-tour-dropdown__list" id="hl-tour-dropdown-list">
                    <!-- Populated by JS from hlTourData.available -->
                </ul>
                <div class="hl-tour-dropdown__empty" id="hl-tour-dropdown-empty" hidden>
                    <?php _e( 'No tours available for this page.', 'hl-core' ); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
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
            <button class="hl-sidebar__collapse-btn" id="hl-sidebar-collapse-btn" type="button" title="<?php esc_attr_e('Collapse sidebar', 'hl-core'); ?>">
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </button>
            <?php HL_Core::render_language_switcher(); ?>
            <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="hl-sidebar__item">
                <span class="hl-sidebar__icon dashicons dashicons-migrate"></span>
                <span><?php esc_html_e('Log Out', 'hl-core'); ?></span>
            </a>
        </div>
    </nav>
<?php endif; ?>

<main class="hl-app__content">
    <?php echo $page_content; ?>
</main>

<?php
// Flush any scripts enqueued during do_shortcode() (e.g. hl_docs).
wp_print_scripts();
?>
<script src="<?php echo esc_url(HL_CORE_ASSETS_URL . 'js/frontend.js'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>"></script>
<?php
// Guided Tours — only for logged-in users, skip in picker mode.
if ( is_user_logged_in() && ! $is_picker_mode ) :
    $tour_service = HL_Tour_Service::instance();
    $user_id      = get_current_user_id();
    $user_roles   = $tour_service->get_user_hl_roles( $user_id );
    $current_page = $_SERVER['REQUEST_URI'];

    // Check active tour slug (passed via URL param by hl-tour.js during page transitions).
    $active_slug = isset( $_GET['hl_active_tour'] ) ? sanitize_text_field( $_GET['hl_active_tour'] ) : null;

    $tour_context = $tour_service->get_tours_for_context( $current_page, $user_id, $user_roles, $active_slug );
    $global_styles = $tour_service->get_global_styles();
?>
    <link rel="stylesheet" href="<?php echo esc_url( HL_CORE_ASSETS_URL . 'css/vendor/driver.css' ); ?>?v=1.4.0">
    <script src="<?php echo esc_url( HL_CORE_ASSETS_URL . 'js/vendor/driver.js' ); ?>?v=1.4.0"></script>
    <script>
        window.hlTourData = <?php echo wp_json_encode( array(
            'auto_trigger' => $tour_context['auto_trigger'],
            'available'    => $tour_context['available'],
            'active_tour'  => $tour_context['active_tour'],
            'styles'       => $global_styles,
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'hl_tour_nonce' ),
            'i18n'         => array(
                'next'          => __( 'Next', 'hl-core' ),
                'prev'          => __( 'Back', 'hl-core' ),
                'done'          => __( 'Done', 'hl-core' ),
                'of'            => __( 'of', 'hl-core' ),
                'replay_title'  => __( 'Replay This Tour', 'hl-core' ),
                'replay_desc'   => __( 'You can revisit this tour anytime by clicking here.', 'hl-core' ),
                'no_tours'      => __( 'No tours available for this page.', 'hl-core' ),
            ),
        ) ); ?>;
    </script>
    <script src="<?php echo esc_url( HL_CORE_ASSETS_URL . 'js/hl-tour.js' ); ?>?ver=<?php echo esc_attr( HL_CORE_VERSION ); ?>"></script>
<?php endif; ?>
<?php if ( $is_picker_mode ) : ?>
    <script src="<?php echo esc_url( HL_CORE_ASSETS_URL . 'js/hl-element-picker.js' ); ?>?ver=<?php echo esc_attr( HL_CORE_VERSION ); ?>"></script>
<?php endif; ?>
</body>
</html>
