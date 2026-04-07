<?php
/**
 * HL Auth Template
 *
 * Full-bleed template for login, password reset, and profile setup pages.
 * No sidebar, no topbar. Centered card layout on gradient background.
 *
 * @package HL_Core
 */
if (!defined('ABSPATH')) exit;

// Prevent caching (spec I8)
nocache_headers();

// Hide WP admin bar on auth pages
show_admin_bar(false);

global $post;
$page_content = do_shortcode($post->post_content);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store">
    <title><?php echo esc_html(get_the_title()); ?> &mdash; Housman Learning</title>
    <?php wp_site_icon(); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo esc_url(HL_CORE_ASSETS_URL . 'css/frontend.css'); ?>?ver=<?php echo esc_attr(HL_CORE_VERSION); ?>">
    <?php
    // PI1: Use wp_head() instead of wp_print_styles/scripts so that
    // jQuery and other dependencies are enqueued properly and plugin
    // hooks (wp_enqueue_scripts) fire correctly.
    wp_enqueue_style('dashicons');
    wp_enqueue_script('jquery');
    wp_enqueue_script('hl-auth', HL_CORE_ASSETS_URL . 'js/hl-auth.js', array('jquery'), HL_CORE_VERSION, true);
    wp_head();
    ?>
</head>
<body class="hl-auth-page">
    <?php
    // Spec FI1: Profile setup needs wider container (680px vs 480px default)
    $wrapper_class = 'hl-auth-wrapper';
    if (strpos($post->post_content, '[hl_profile_setup]') !== false) {
        $wrapper_class .= ' hl-auth-wrapper--wide';
    }
    ?>
    <div class="<?php echo esc_attr($wrapper_class); ?>">
        <?php echo $page_content; ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
