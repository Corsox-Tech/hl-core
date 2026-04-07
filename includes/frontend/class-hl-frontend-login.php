<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_login] shortcode.
 *
 * Renders the login form on GET. POST handling is in HL_Auth_Manager.
 * Uses PRG pattern for error display (spec C5).
 *
 * @package HL_Core
 */
class HL_Frontend_Login {

    public static function render($atts) {
        // Check for error from PRG redirect (spec C5)
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

        // Logo
        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // PI7: Session token for hidden field (cookie already set in template_redirect) -- sanitize on read
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';

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
                    <label for="hl-login-email"><?php esc_html_e('Email or Username', 'hl-core'); ?></label>
                    <input type="text" id="hl-login-email" name="hl_login_email"
                           autocomplete="username"
                           required
                           placeholder="<?php esc_attr_e('Email or username', 'hl-core'); ?>">
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
