<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_password_reset] shortcode.
 *
 * Two modes:
 * 1. Request form (default) — user enters email to receive a reset link.
 * 2. Set-password form — when `key` and `login` query params are present
 *    (from the email link), validates the key and renders a new-password form.
 *
 * POST handling for both forms is in HL_Auth_Manager.
 *
 * @package HL_Core
 */
class HL_Frontend_Password_Reset {

    public static function render($atts) {
        // Determine mode: set-password (key+login present) vs request
        $reset_key   = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $reset_login = isset($_GET['login']) ? sanitize_text_field($_GET['login']) : '';

        if (!empty($reset_key) && !empty($reset_login)) {
            return self::render_set_password($reset_key, $reset_login);
        }

        return self::render_request_form();
    }

    /**
     * Render the "request reset link" form.
     */
    private static function render_request_form() {
        $show_success = isset($_GET['hl_reset_sent']) && $_GET['hl_reset_sent'] === '1';

        // Show success after password was changed (redirected from set-password form)
        $password_changed = isset($_GET['hl_password_changed']) && $_GET['hl_password_changed'] === '1';

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

            <?php if ($password_changed) : ?>
                <div class="hl-auth-success" role="status">
                    <?php esc_html_e('Your password has been changed successfully. You can now sign in with your new password.', 'hl-core'); ?>
                </div>
            <?php elseif ($show_success) : ?>
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

    /**
     * Render the "set new password" form.
     *
     * Validates the reset key first. If invalid/expired, shows an error
     * with a link to request a new one.
     */
    private static function render_set_password($reset_key, $reset_login) {
        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // Validate the reset key using WP core
        $user = check_password_reset_key($reset_key, $reset_login);

        // Check for error from POST handler (PRG)
        $error_message = '';
        if (isset($_GET['hl_rp_error'])) {
            $error_code = sanitize_text_field($_GET['hl_rp_error']);
            $error_messages = array(
                'password_mismatch' => __('Passwords do not match. Please try again.', 'hl-core'),
                'password_empty'    => __('Please enter a new password.', 'hl-core'),
                'password_short'    => __('Password must be at least 8 characters.', 'hl-core'),
                'reset_failed'      => __('Password reset failed. Please request a new reset link.', 'hl-core'),
            );
            $error_message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : '';
        }

        ob_start();
        ?>
        <div class="hl-auth-card">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <?php if (is_wp_error($user)) : ?>
                <h1 class="hl-auth-title"><?php esc_html_e('Invalid or Expired Link', 'hl-core'); ?></h1>
                <div class="hl-auth-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('This password reset link is invalid or has expired. Please request a new one.', 'hl-core'); ?>
                </div>
                <div class="hl-auth-links">
                    <a href="<?php echo esc_url(HL_Auth_Service::get_password_reset_page_url() ?: wp_lostpassword_url()); ?>"><?php esc_html_e('Request New Reset Link', 'hl-core'); ?></a>
                </div>
            <?php else : ?>
                <h1 class="hl-auth-title"><?php esc_html_e('Set New Password', 'hl-core'); ?></h1>
                <p class="hl-auth-subtitle"><?php esc_html_e('Enter your new password below.', 'hl-core'); ?></p>

                <?php if ($error_message) : ?>
                    <div class="hl-auth-error" role="alert">
                        <span class="dashicons dashicons-warning"></span>
                        <?php echo esc_html($error_message); ?>
                    </div>
                <?php endif; ?>

                <form class="hl-auth-form" method="post" action="">
                    <?php wp_nonce_field('hl_set_password_action', 'hl_set_password_nonce'); ?>
                    <input type="hidden" name="hl_auth_action" value="set_password">
                    <input type="hidden" name="hl_rp_key" value="<?php echo esc_attr($reset_key); ?>">
                    <input type="hidden" name="hl_rp_login" value="<?php echo esc_attr($reset_login); ?>">

                    <div class="hl-auth-field">
                        <label for="hl-new-password"><?php esc_html_e('New Password', 'hl-core'); ?></label>
                        <input type="password" id="hl-new-password" name="hl_new_password"
                               autocomplete="new-password"
                               required minlength="8"
                               placeholder="<?php esc_attr_e('At least 8 characters', 'hl-core'); ?>">
                    </div>

                    <div class="hl-auth-field">
                        <label for="hl-confirm-password"><?php esc_html_e('Confirm Password', 'hl-core'); ?></label>
                        <input type="password" id="hl-confirm-password" name="hl_confirm_password"
                               autocomplete="new-password"
                               required minlength="8"
                               placeholder="<?php esc_attr_e('Re-enter your password', 'hl-core'); ?>">
                    </div>

                    <button type="submit" class="hl-auth-btn" id="hl-set-password-btn">
                        <span class="hl-auth-btn-text"><?php esc_html_e('Reset Password', 'hl-core'); ?></span>
                    </button>
                </form>

                <div class="hl-auth-links">
                    <a href="<?php echo esc_url(HL_Auth_Service::get_login_page_url() ?: wp_login_url()); ?>"><?php esc_html_e('Back to Sign In', 'hl-core'); ?></a>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
