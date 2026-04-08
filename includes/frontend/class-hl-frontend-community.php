<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_community] shortcode.
 *
 * Wraps the BuddyBoss/bbPress [bbp-forum-index] shortcode inside the
 * HL Core template shell (sidebar, topbar, access control).
 *
 * Visible to: users enrolled in active cycles (which includes mentors),
 * coaches (WP role), and staff.
 *
 * @package HL_Core
 */
class HL_Frontend_Community {

    /**
     * Render the community page.
     *
     * @param array $atts Shortcode attributes (unused).
     * @return string HTML output.
     */
    public function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="hl-notice hl-notice-warning">' . __('Please log in to view this page.', 'hl-core') . '</div>';
        }

        $user_id = get_current_user_id();

        // Access: enrolled in active cycle, OR coach WP role, OR staff.
        // Mentors are covered by enrollment check (mentor is an enrollment role).
        $bb       = HL_BuddyBoss_Integration::instance();
        $roles    = $bb->get_user_hl_roles($user_id);
        $wp_user  = wp_get_current_user();
        $is_coach = in_array('coach', (array) $wp_user->roles, true);
        $is_staff = current_user_can('manage_hl_core');

        if (empty($roles) && !$is_coach && !$is_staff) {
            return '<div class="hl-notice hl-notice-error">' . __('You do not have access to this page.', 'hl-core') . '</div>';
        }

        // Enqueue bbPress assets manually — the HL template bypasses wp_head()
        // so the wp_enqueue_scripts action never fires. bbPress registers its
        // styles/scripts on that hook, so we trigger them explicitly here.
        if (function_exists('bbp_enqueue_scripts')) {
            bbp_enqueue_scripts();
        }
        if (function_exists('bbp_enqueue_styles')) {
            bbp_enqueue_styles();
        }

        ob_start();
        ?>
        <div class="hl-community">
            <?php echo do_shortcode('[bbp-forum-index]'); ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
