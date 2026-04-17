<?php
/**
 * Survey Modal — frontend shell + AJAX check/submit endpoints.
 *
 * Renders an empty modal shell on trigger pages (Program Page, Dashboard).
 * JS fires an AJAX check on page load; if a pending survey exists, the server
 * returns fully-rendered HTML which JS injects into the shell. Submission goes
 * through a separate AJAX endpoint with nonce + ownership + rate-limit checks.
 *
 * @package HL_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HL_Frontend_Survey_Modal {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register AJAX handlers and frontend hooks.
     */
    public function init() {
        // AJAX endpoints — must fire on admin-ajax.php for logged-in users.
        add_action( 'wp_ajax_hl_check_pending_surveys', array( $this, 'ajax_check_pending' ) );
        add_action( 'wp_ajax_hl_submit_survey', array( $this, 'ajax_submit' ) );

        // Frontend-only: modal shell + conditional asset enqueue.
        if ( ! is_admin() ) {
            add_action( 'wp_footer', array( $this, 'render_modal_shell' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
        }
    }

    /**
     * Conditionally enqueue survey modal JS/CSS only on trigger pages.
     */
    public function maybe_enqueue_assets() {
        if ( ! $this->is_survey_trigger_page() ) {
            return;
        }
        wp_enqueue_style( 'hl-survey-modal', HL_CORE_PLUGIN_URL . 'assets/css/survey-modal.css', array(), HL_CORE_VERSION );
        wp_enqueue_script( 'hl-survey-modal', HL_CORE_PLUGIN_URL . 'assets/js/survey-modal.js', array( 'jquery' ), HL_CORE_VERSION, true );
        wp_localize_script( 'hl-survey-modal', 'hlSurveyModal', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
        ) );
    }

    /**
     * Render empty modal shell — populated via AJAX (cache-safe).
     */
    public function render_modal_shell() {
        if ( ! is_user_logged_in() ) {
            return;
        }
        // Only on Program Page and Dashboard.
        if ( ! $this->is_survey_trigger_page() ) {
            return;
        }
        ?>
        <div id="hl-survey-modal-shell" style="display:none;"
             data-check-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
             data-check-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_check_pending_surveys' ) ); ?>">
            <div class="hl-survey-overlay" style="display:none;"></div>
            <div class="hl-survey-modal" role="dialog" aria-modal="true" aria-labelledby="hl-survey-title" style="display:none;">
                <div class="hl-survey-loading">
                    <div class="hl-spinner"></div>
                </div>
                <div class="hl-survey-content" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    // ── AJAX Endpoints ─────────────────────────────────────

    /**
     * AJAX: Check if user has pending surveys.
     *
     * Returns {has_pending:false} or {has_pending:true, html:..., pending_id:..., submit_nonce:...}.
     */
    public function ajax_check_pending() {
        check_ajax_referer( 'hl_check_pending_surveys', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        $service = new HL_Survey_Service();
        $data = $service->get_next_pending_for_user( get_current_user_id() );

        if ( ! $data ) {
            wp_send_json_success( array( 'has_pending' => false ) );
        }

        $survey  = $data['survey'];
        $pending = $data['pending'];

        // Get enrollment language.
        global $wpdb;
        $lang = $wpdb->get_var( $wpdb->prepare(
            "SELECT language_preference FROM {$wpdb->prefix}hl_enrollment WHERE enrollment_id = %d",
            $pending['enrollment_id']
        ) ) ?: 'en';

        // Build modal HTML server-side.
        $html = $this->build_modal_html( $survey, $pending, $lang );

        wp_send_json_success( array(
            'has_pending'  => true,
            'html'         => $html,
            'pending_id'   => $pending['pending_id'],
            'submit_nonce' => wp_create_nonce( 'hl_survey_submit_' . $pending['pending_id'] ),
        ) );
    }

    /**
     * AJAX: Submit survey response.
     *
     * Security: nonce + login + user ownership + rate limit.
     */
    public function ajax_submit() {
        $pending_id = absint( $_POST['pending_id'] ?? 0 );
        if ( ! $pending_id ) {
            wp_send_json_error( 'Missing pending ID.' );
        }

        check_ajax_referer( 'hl_survey_submit_' . $pending_id, 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Not logged in.' );
        }

        // Rate limit: 3-second cooldown per user.
        $throttle_key = 'hl_survey_throttle_' . get_current_user_id();
        if ( get_transient( $throttle_key ) ) {
            wp_send_json_error( 'Please wait a moment before submitting again.' );
        }

        // User ownership check: verify current user owns this pending survey.
        $response_repo = new HL_Survey_Response_Repository();
        $pending = $response_repo->get_pending_by_id( $pending_id );
        if ( ! $pending || (int) $pending['user_id'] !== get_current_user_id() ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $responses = json_decode( wp_unslash( $_POST['responses'] ?? '{}' ), true );
        if ( ! is_array( $responses ) ) {
            wp_send_json_error( 'Invalid responses.' );
        }

        // Set rate limit BEFORE submit to block concurrent rapid requests.
        set_transient( $throttle_key, 1, 3 );

        $service = new HL_Survey_Service();
        $result  = $service->submit_response( $pending_id, $responses );

        if ( $result === 'already_submitted' ) {
            wp_send_json_success( array( 'status' => 'already_submitted', 'message' => __( 'Survey already submitted.', 'hl-core' ) ) );
        }

        if ( is_wp_error( $result ) ) {
            delete_transient( $throttle_key ); // Clear on failure so user can retry.
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array( 'status' => 'submitted', 'response_id' => $result ) );
    }

    // ── Private Helpers ────────────────────────────────────

    /**
     * Build the modal inner HTML for a pending survey.
     *
     * Renders title, intro, questions (likert_5 / yes_no / open_text) with
     * group headers, error region, and submit button. All multilingual via
     * the HL_Survey domain model helpers.
     *
     * @param HL_Survey $survey  Survey domain model.
     * @param array     $pending Pending row (pending_id, course_title, etc.).
     * @param string    $lang    Language code: en, es, or pt.
     * @return string HTML.
     */
    private function build_modal_html( $survey, $pending, $lang ) {
        ob_start();
        ?>
        <h2 id="hl-survey-title"><?php echo esc_html( $survey->display_name ); ?></h2>
        <p class="hl-survey-course-name"><?php echo esc_html( $pending['course_title'] ); ?></p>

        <?php $intro = $survey->get_intro_text( $lang ); if ( $intro ) : ?>
            <p class="hl-survey-intro"><?php echo esc_html( $intro ); ?></p>
        <?php endif; ?>

        <form id="hl-survey-form" data-pending-id="<?php echo esc_attr( $pending['pending_id'] ); ?>">
        <?php
        $questions     = $survey->get_questions();
        $current_group = null;

        foreach ( $questions as $q ) :
            // Group header.
            if ( ! empty( $q['group'] ) && $q['group'] !== $current_group ) :
                $current_group = $q['group'];
                $group_label   = $survey->get_group_label( $current_group, $lang );
                if ( $group_label ) : ?>
                    <div class="hl-survey-group-header">
                        <p class="hl-survey-group-label"><?php echo esc_html( $group_label ); ?></p>
                    </div>
                <?php endif;
            endif;

            $qtext = $survey->get_question_text( $q, $lang );

            if ( $q['type'] === 'likert_5' ) : ?>
                <fieldset class="hl-survey-question hl-survey-likert" data-key="<?php echo esc_attr( $q['question_key'] ); ?>">
                    <legend><?php echo esc_html( $qtext ); ?></legend>
                    <div class="hl-survey-pills" role="radiogroup" aria-label="<?php echo esc_attr( $qtext ); ?>">
                        <?php for ( $v = 1; $v <= 5; $v++ ) :
                            $label    = $survey->get_scale_label_text( 'likert_5', $v, $lang );
                            $input_id = 'hl-q-' . esc_attr( $q['question_key'] ) . '-' . $v;
                            ?>
                            <label class="hl-survey-pill" for="<?php echo $input_id; ?>">
                                <input type="radio"
                                       id="<?php echo $input_id; ?>"
                                       name="<?php echo esc_attr( $q['question_key'] ); ?>"
                                       value="<?php echo esc_attr( $v ); ?>"
                                       <?php echo $v === 1 ? 'tabindex="0"' : 'tabindex="-1"'; ?>
                                       role="radio"
                                       aria-checked="false"
                                       <?php echo ! empty( $q['required'] ) ? 'required' : ''; ?>>
                                <span class="hl-pill-text"><?php echo esc_html( $label ); ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                </fieldset>

            <?php elseif ( $q['type'] === 'yes_no' ) : ?>
                <fieldset class="hl-survey-question hl-survey-yes-no" data-key="<?php echo esc_attr( $q['question_key'] ); ?>">
                    <legend><?php echo esc_html( $qtext ); ?></legend>
                    <div class="hl-survey-pills" role="radiogroup" aria-label="<?php echo esc_attr( $qtext ); ?>">
                        <label class="hl-survey-pill" for="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>-yes">
                            <input type="radio" id="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>-yes"
                                   name="<?php echo esc_attr( $q['question_key'] ); ?>" value="yes"
                                   tabindex="0" role="radio" aria-checked="false"
                                   <?php echo ! empty( $q['required'] ) ? 'required' : ''; ?>>
                            <span class="hl-pill-text"><?php esc_html_e( 'Yes', 'hl-core' ); ?></span>
                        </label>
                        <label class="hl-survey-pill" for="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>-no">
                            <input type="radio" id="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>-no"
                                   name="<?php echo esc_attr( $q['question_key'] ); ?>" value="no"
                                   tabindex="-1" role="radio" aria-checked="false">
                            <span class="hl-pill-text"><?php esc_html_e( 'No', 'hl-core' ); ?></span>
                        </label>
                    </div>
                </fieldset>

            <?php elseif ( $q['type'] === 'open_text' ) : ?>
                <div class="hl-survey-question hl-survey-open-text" data-key="<?php echo esc_attr( $q['question_key'] ); ?>">
                    <label for="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>"><?php echo esc_html( $qtext ); ?></label>
                    <textarea id="hl-q-<?php echo esc_attr( $q['question_key'] ); ?>"
                              name="<?php echo esc_attr( $q['question_key'] ); ?>"
                              rows="3"
                              <?php echo ! empty( $q['required'] ) ? 'required' : ''; ?>></textarea>
                </div>
            <?php endif;

        endforeach;
        ?>
            <div class="hl-survey-error" aria-live="polite" style="display:none;"></div>
            <div class="hl-survey-actions">
                <button type="submit" class="hl-btn hl-btn-primary" id="hl-survey-submit">
                    <?php echo esc_html__( 'Submit Survey', 'hl-core' ); ?>
                </button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Check if the current page is a survey trigger page.
     *
     * Trigger pages: hl_program_page, hl_dashboard shortcodes.
     * Fallback for BuddyBoss where $post may be null: uses HL_Page_Cache.
     *
     * @return bool
     */
    private function is_survey_trigger_page() {
        global $post;
        // Primary check: shortcode in post content.
        if ( $post && ! empty( $post->post_content ) ) {
            if ( has_shortcode( $post->post_content, 'hl_program_page' )
                || has_shortcode( $post->post_content, 'hl_dashboard' ) ) {
                return true;
            }
        }
        // Fallback for BuddyBoss virtual pages where $post may be null/empty.
        // Uses HL_Page_Cache which maps shortcodes to page IDs.
        if ( class_exists( 'HL_Page_Cache' ) ) {
            $current_id   = get_queried_object_id();
            $program_id   = HL_Page_Cache::get_id( 'hl_program_page' );
            $dashboard_id = HL_Page_Cache::get_id( 'hl_dashboard' );
            if ( $current_id && ( $current_id === $program_id || $current_id === $dashboard_id ) ) {
                return true;
            }
        }
        return false;
    }
}
