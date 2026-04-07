<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_password_reset] shortcode.
 *
 * Renders the "request reset link" form. POST handling is in HL_Auth_Manager.
 * Always shows a neutral success message (prevents user enumeration).
 *
 * Note: The actual new-password form stays on wp-login.php?action=rp
 * because WP core handles key validation there. See spec note after D.
 *
 * @package HL_Core
 */
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
