<?php
if (!defined('ABSPATH')) exit;

/**
 * Renders a child assessment instrument as a polished, branded HTML form.
 *
 * For B2E single-question Likert instruments, produces a transposed matrix
 * with one column per scale value (Never … Almost Always) and one row per
 * child. Includes branded header, teacher info, instructions, behavior key,
 * and question context — matching the Housman Learning Academy design.
 *
 * For multi-question or non-Likert instruments, falls back to a generic
 * column-per-question layout.
 *
 * This is NOT a singleton — instantiated with data each time.
 *
 * @package HL_Core
 * @since   1.0.0
 */
class HL_Instrument_Renderer {

    /** @var object Instrument data with instrument_id, name, questions. */
    private $instrument;

    /** @var array Parsed questions array from instrument JSON. */
    private $questions;

    /** @var array Array of child objects (child_id, first_name, last_name, child_display_code, dob). */
    private $children;

    /** @var int child assessment instance ID. */
    private $instance_id;

    /** @var array Existing answers keyed by child_id, each containing decoded answers_json. */
    private $existing_answers;

    /** @var array Full instance data (display_name, school_name, classroom_name, phase, track_name, etc.). */
    private $instance;

    /** @var bool Whether this renderer is in multi-age-group mode. */
    private $multi_age_group = false;

    /** @var array Children grouped by age group. Keyed by age_group slug. */
    private $children_by_age_group = array();

    /** @var array Instrument objects keyed by age group slug. */
    private $instruments_by_age_group = array();

    /** @var array Parsed questions arrays keyed by age group slug. */
    private $questions_by_age_group = array();

    /**
     * Constructor.
     *
     * @param object|array $instrument       Instrument with instrument_id, name, questions (JSON string or array).
     * @param array        $children         Array of child objects.
     * @param int          $instance_id      The child_assessment_instance ID.
     * @param array        $existing_answers Optional. Existing answer rows keyed by child_id.
     * @param array        $instance         Optional. Full instance data from get_child_assessment().
     */
    public function __construct( $instrument, $children, $instance_id, $existing_answers = array(), $instance = array() ) {
        $this->instrument       = (object) $instrument;
        $this->children         = is_array( $children ) ? $children : array();
        $this->instance_id      = absint( $instance_id );
        $this->existing_answers = is_array( $existing_answers ) ? $existing_answers : array();
        $this->instance         = is_array( $instance ) ? $instance : array();

        // Parse questions from JSON string if needed.
        $raw_questions = isset( $this->instrument->questions ) ? $this->instrument->questions : '[]';
        if ( is_string( $raw_questions ) ) {
            $decoded = json_decode( $raw_questions, true );
            $this->questions = is_array( $decoded ) ? $decoded : array();
        } elseif ( is_array( $raw_questions ) ) {
            $this->questions = $raw_questions;
        } else {
            $this->questions = array();
        }
    }

    /**
     * Factory: create a renderer configured for multi-age-group sections.
     *
     * Assembles data: gets active children, gets frozen snapshots, groups by
     * age group, loads per-age-group instruments.
     *
     * @param int   $classroom_id     Classroom ID.
     * @param int   $track_id         Track ID.
     * @param int   $instance_id      Instance ID.
     * @param array $existing_answers Existing answer rows keyed by child_id.
     * @param array $instance         Full instance data.
     * @param array $children         Pre-fetched active children (optional).
     * @return self Configured renderer.
     */
    public static function create_multi_age_group( $classroom_id, $track_id, $instance_id, $existing_answers = array(), $instance = array(), $children = null ) {
        global $wpdb;

        // 1. Get active children for classroom.
        if ( $children === null ) {
            $classroom_service = new HL_Classroom_Service();
            $children = $classroom_service->get_children_in_classroom( $classroom_id );
        }

        // 2. Get frozen age groups from snapshots.
        $snapshots = HL_Child_Snapshot_Service::get_snapshots_for_classroom( $classroom_id, $track_id );

        // 3. Ensure snapshots exist for any children missing them.
        foreach ( $children as $child ) {
            $child = (object) $child;
            if ( ! isset( $snapshots[ $child->child_id ] ) && ! empty( $child->dob ) ) {
                HL_Child_Snapshot_Service::ensure_snapshot( $child->child_id, $track_id, $child->dob );
                $snapshots[ $child->child_id ] = (object) array(
                    'child_id'         => $child->child_id,
                    'frozen_age_group' => HL_Age_Group_Helper::calculate_age_group( $child->dob ),
                );
            }
        }

        // 3. Group children by age group.
        $children_by_group = array();
        foreach ( $children as $child ) {
            $child    = (object) $child;
            $snapshot = isset( $snapshots[ $child->child_id ] ) ? $snapshots[ $child->child_id ] : null;
            $group    = $snapshot ? $snapshot->frozen_age_group : 'preschool'; // fallback
            if ( ! isset( $children_by_group[ $group ] ) ) {
                $children_by_group[ $group ] = array();
            }
            $children_by_group[ $group ][] = $child;
        }

        // 4. Load correct instrument for each age group.
        $instruments_by_group = array();
        foreach ( array_keys( $children_by_group ) as $group ) {
            $instrument_type = HL_Age_Group_Helper::get_instrument_type_for_age_group( $group );
            if ( $instrument_type ) {
                $instrument = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}hl_instrument
                     WHERE instrument_type = %s
                     AND (effective_to IS NULL OR effective_to >= CURDATE())
                     ORDER BY effective_from DESC LIMIT 1",
                    $instrument_type
                ) );
                if ( $instrument ) {
                    $instruments_by_group[ $group ] = $instrument;
                }
            }

            // Fallback: try children_mixed or any children_* instrument.
            if ( ! isset( $instruments_by_group[ $group ] ) ) {
                $instrument = $wpdb->get_row(
                    "SELECT * FROM {$wpdb->prefix}hl_instrument
                     WHERE instrument_type LIKE 'children_%'
                     AND (effective_to IS NULL OR effective_to >= CURDATE())
                     ORDER BY effective_from DESC LIMIT 1"
                );
                if ( $instrument ) {
                    $instruments_by_group[ $group ] = $instrument;
                }
            }
        }

        // 5. Create renderer with first available instrument for backward compat.
        $first_instrument = ! empty( $instruments_by_group ) ? reset( $instruments_by_group ) : (object) array( 'questions' => '[]' );
        $all_children     = array();
        foreach ( $children_by_group as $group_children ) {
            $all_children = array_merge( $all_children, $group_children );
        }

        $renderer = new self( $first_instrument, $all_children, $instance_id, $existing_answers, $instance );

        // Set multi-age-group mode.
        $renderer->multi_age_group          = true;
        $renderer->children_by_age_group    = $children_by_group;
        $renderer->instruments_by_age_group = $instruments_by_group;

        // Parse questions per age group.
        foreach ( $instruments_by_group as $group => $instr ) {
            $raw = isset( $instr->questions ) ? $instr->questions : '[]';
            if ( is_string( $raw ) ) {
                $decoded = json_decode( $raw, true );
                $renderer->questions_by_age_group[ $group ] = is_array( $decoded ) ? $decoded : array();
            } else {
                $renderer->questions_by_age_group[ $group ] = is_array( $raw ) ? $raw : array();
            }
        }

        return $renderer;
    }

    /**
     * Render the full branded child assessment form.
     *
     * @return string HTML output (does not echo).
     */
    public function render() {
        if ( empty( $this->children ) ) {
            ob_start();
            ?>
            <div class="hl-ca-notice hl-ca-notice-warning">
                <p><?php esc_html_e( 'No children in this classroom.', 'hl-core' ); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        if ( empty( $this->questions ) ) {
            ob_start();
            ?>
            <div class="hl-ca-notice hl-ca-notice-warning">
                <p><?php esc_html_e( 'No questions configured for this instrument.', 'hl-core' ); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        $is_single_likert = $this->is_single_question_likert();

        ob_start();

        $this->render_inline_styles();

        ?>
        <div class="hl-ca-form-wrap">

            <?php $this->render_branded_header(); ?>

            <?php $this->render_teacher_info(); ?>

            <?php if ( $this->multi_age_group ) : ?>
                <?php // Multi-age-group mode: instructions first, then per-section content inside the form. ?>
                <?php $this->render_instructions(); ?>

                <form method="post" action="" class="hl-ca-matrix-form" id="hl-ca-form-<?php echo esc_attr( $this->instance_id ); ?>">
                    <?php wp_nonce_field( 'hl_child_assessment_' . $this->instance_id, '_hl_assessment_nonce' ); ?>
                    <input type="hidden" name="hl_instrument_instance_id" value="<?php echo esc_attr( $this->instance_id ); ?>" />
                    <input type="hidden" name="hl_requires_validation" id="hl-ca-requires-validation-<?php echo esc_attr( $this->instance_id ); ?>" value="0" />

                    <?php $this->render_age_group_sections(); ?>

                    <?php $this->render_missing_child_link(); ?>

                    <div class="hl-ca-actions">
                        <button type="submit" name="hl_assessment_action" value="draft"
                                class="hl-btn hl-btn-secondary hl-ca-btn-draft"
                                id="hl-ca-btn-draft-<?php echo esc_attr( $this->instance_id ); ?>">
                            <?php esc_html_e( 'Save Draft', 'hl-core' ); ?>
                        </button>
                        <button type="submit" name="hl_assessment_action" value="submit"
                                class="hl-btn hl-btn-primary hl-ca-btn-submit"
                                id="hl-ca-btn-submit-<?php echo esc_attr( $this->instance_id ); ?>">
                            <?php esc_html_e( 'Submit Assessment', 'hl-core' ); ?>
                        </button>
                    </div>
                </form>

            <?php else : ?>
                <?php // Legacy single-instrument mode. ?>
                <?php if ( $is_single_likert ) : ?>
                    <?php $this->render_instructions(); ?>
                    <?php $this->render_behavior_key(); ?>
                    <?php $this->render_question_section(); ?>
                <?php endif; ?>

                <form method="post" action="" class="hl-ca-matrix-form" id="hl-ca-form-<?php echo esc_attr( $this->instance_id ); ?>">
                    <?php wp_nonce_field( 'hl_child_assessment_' . $this->instance_id, '_hl_assessment_nonce' ); ?>
                    <input type="hidden" name="hl_instrument_instance_id" value="<?php echo esc_attr( $this->instance_id ); ?>" />
                    <input type="hidden" name="hl_requires_validation" id="hl-ca-requires-validation-<?php echo esc_attr( $this->instance_id ); ?>" value="0" />

                    <div class="hl-ca-matrix-wrap">
                        <?php
                        if ( $is_single_likert ) {
                            $this->render_transposed_likert_matrix();
                        } else {
                            $this->render_multi_question_matrix();
                        }
                        ?>
                    </div>

                    <?php $this->render_missing_child_link(); ?>

                    <div class="hl-ca-actions">
                        <button type="submit" name="hl_assessment_action" value="draft"
                                class="hl-btn hl-btn-secondary hl-ca-btn-draft"
                                id="hl-ca-btn-draft-<?php echo esc_attr( $this->instance_id ); ?>">
                            <?php esc_html_e( 'Save Draft', 'hl-core' ); ?>
                        </button>
                        <button type="submit" name="hl_assessment_action" value="submit"
                                class="hl-btn hl-btn-primary hl-ca-btn-submit"
                                id="hl-ca-btn-submit-<?php echo esc_attr( $this->instance_id ); ?>">
                            <?php esc_html_e( 'Submit Assessment', 'hl-core' ); ?>
                        </button>
                    </div>
                </form>

            <?php endif; ?>

        </div>
        <?php

        $this->render_inline_script();

        return ob_get_clean();
    }

    // ─── Branded Header & Context Sections ───────────────────────────────

    /**
     * Render the branded header with Housman Learning text logo and assessment title.
     */
    private function render_branded_header() {
        $phase = isset( $this->instance['phase'] ) ? $this->instance['phase'] : 'pre';
        $phase_label = ( $phase === 'post' ) ? __( 'Post', 'hl-core' ) : __( 'Pre', 'hl-core' );
        $instrument_name = isset( $this->instrument->name ) ? $this->instrument->name : __( 'Child Assessment', 'hl-core' );
        ?>
        <div class="hl-ca-branded-header">
            <div class="hl-ca-brand-logo">
                <img src="<?php echo esc_url( content_url( '/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg' ) ); ?>"
                     alt="<?php esc_attr_e( 'Housman Learning', 'hl-core' ); ?>"
                     class="hl-ca-brand-img" />
            </div>
            <h2 class="hl-ca-title">
                <?php echo esc_html( $instrument_name ); ?>
                <span class="hl-ca-phase-label">(<?php echo esc_html( $phase_label ); ?>)</span>
            </h2>
        </div>
        <?php
    }

    /**
     * Render teacher, school, and classroom info section.
     */
    private function render_teacher_info() {
        $teacher   = isset( $this->instance['display_name'] ) ? $this->instance['display_name'] : '';
        $school    = isset( $this->instance['school_name'] ) ? $this->instance['school_name'] : '';
        $classroom = isset( $this->instance['classroom_name'] ) ? $this->instance['classroom_name'] : '';

        if ( empty( $teacher ) && empty( $school ) && empty( $classroom ) ) {
            return;
        }
        ?>
        <div class="hl-ca-teacher-info">
            <?php if ( $teacher ) : ?>
                <div class="hl-ca-info-row">
                    <span class="hl-ca-info-label"><?php esc_html_e( 'Teacher:', 'hl-core' ); ?></span>
                    <span class="hl-ca-info-value"><?php echo esc_html( $teacher ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( $school ) : ?>
                <div class="hl-ca-info-row">
                    <span class="hl-ca-info-label"><?php esc_html_e( 'School:', 'hl-core' ); ?></span>
                    <span class="hl-ca-info-value"><?php echo esc_html( $school ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( $classroom ) : ?>
                <div class="hl-ca-info-row">
                    <span class="hl-ca-info-label"><?php esc_html_e( 'Classroom:', 'hl-core' ); ?></span>
                    <span class="hl-ca-info-value"><?php echo esc_html( $classroom ); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the instructions section for B2E Likert instruments.
     */
    private function render_instructions() {
        ?>
        <div class="hl-ca-instructions">
            <h3><?php esc_html_e( 'Instructions:', 'hl-core' ); ?></h3>
            <p>
                <?php
                echo wp_kses(
                    __( 'This questionnaire will ask you about your students. For the question stated below, choose from the Likert Scale from &ldquo;Never&rdquo; to &ldquo;Almost Always&rdquo; that best describes each student in your classroom. An Example Behavior chart is provided to explain the scale you will use to assess your students. As you ask yourself the following question, you should <strong>collaborate with your co-teachers</strong> to choose the answer that best characterizes each of your students.', 'hl-core' ),
                    array( 'strong' => array() )
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the Key & Example Behavior table for Likert scales.
     *
     * Content is age-band-specific per the B2E Child Assessment instrument.
     */
    private function render_behavior_key() {
        $age_band = isset( $this->instance['instrument_age_band'] ) ? $this->instance['instrument_age_band'] : '';
        $scale_descriptions = $this->get_behavior_key_for_age_band( $age_band );
        ?>
        <div class="hl-ca-behavior-key">
            <table class="hl-ca-key-table">
                <thead>
                    <tr>
                        <th colspan="2"><?php esc_html_e( 'Key & Example Behavior', 'hl-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $scale_descriptions as $item ) : ?>
                        <tr>
                            <td class="hl-ca-key-label-cell">
                                <strong><?php echo esc_html( $item['label'] ); ?></strong>
                                <span class="hl-ca-key-freq"><?php echo esc_html( $item['frequency'] ); ?></span>
                            </td>
                            <td class="hl-ca-key-desc-cell">
                                <?php echo esc_html( $item['description'] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the question prompt section above the matrix.
     */
    private function render_question_section() {
        if ( empty( $this->questions ) ) {
            return;
        }

        // For single-question mode, show the question prominently.
        $question = $this->questions[0];
        ?>
        <div class="hl-ca-question-section">
            <h3><?php esc_html_e( 'Question', 'hl-core' ); ?></h3>
            <p class="hl-ca-question-text">
                <?php echo esc_html( $question['prompt_text'] ); ?>
            </p>
        </div>
        <?php
    }

    // ─── Multi-Age-Group Sections ────────────────────────────────────────

    /**
     * Render all age group sections (multi-age-group mode).
     *
     * Each section has its own header, behavior key, question, and Likert matrix.
     */
    private function render_age_group_sections() {
        // Sort age groups in canonical order.
        $order = array( 'infant', 'toddler', 'preschool', 'k2' );
        $groups = array_intersect( $order, array_keys( $this->children_by_age_group ) );

        foreach ( $groups as $group ) {
            $group_children = $this->children_by_age_group[ $group ];
            $group_instrument = isset( $this->instruments_by_age_group[ $group ] ) ? $this->instruments_by_age_group[ $group ] : null;
            $group_questions  = isset( $this->questions_by_age_group[ $group ] ) ? $this->questions_by_age_group[ $group ] : array();

            if ( empty( $group_children ) ) {
                continue;
            }

            $label = HL_Age_Group_Helper::get_label( $group );
            $count = count( $group_children );
            ?>
            <div class="hl-ca-age-group-section" data-age-group="<?php echo esc_attr( $group ); ?>">
                <h3 class="hl-ca-age-group-header">
                    <?php
                    printf(
                        esc_html__( '%1$s (%2$d %3$s)', 'hl-core' ),
                        esc_html( $label ),
                        $count,
                        _n( 'child', 'children', $count, 'hl-core' )
                    );
                    ?>
                </h3>

                <?php
                // Render behavior key for this age group.
                $saved_instance = $this->instance;
                $this->instance['instrument_age_band'] = $group;
                $this->render_behavior_key();
                $this->instance = $saved_instance;

                // Render question section for this age group.
                if ( ! empty( $group_questions ) ) {
                    $q = $group_questions[0];
                    ?>
                    <div class="hl-ca-question-section">
                        <h3><?php esc_html_e( 'Question', 'hl-core' ); ?></h3>
                        <p class="hl-ca-question-text"><?php echo esc_html( $q['prompt_text'] ); ?></p>
                    </div>
                    <?php
                }

                // Render Likert matrix for this age group's children.
                if ( ! empty( $group_questions ) ) {
                    $q            = $group_questions[0];
                    $qid          = $q['question_id'];
                    $is_required  = ! empty( $q['required'] );
                    $allowed_vals = $this->parse_allowed_values( $q );
                    if ( empty( $allowed_vals ) ) {
                        $allowed_vals = array( '0', '1', '2', '3', '4' );
                    }
                    $use_labels = $this->is_numeric_likert( $allowed_vals );
                    $instrument_id = $group_instrument ? $group_instrument->instrument_id : '';
                    ?>
                    <div class="hl-ca-matrix-wrap">
                        <table class="hl-ca-matrix">
                            <thead>
                                <tr>
                                    <th class="hl-ca-child-header">&nbsp;</th>
                                    <?php foreach ( $allowed_vals as $val ) : ?>
                                        <th class="hl-ca-scale-header">
                                            <?php echo esc_html( $use_labels ? $this->get_likert_label( $val ) : $val ); ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $row_index = 0;
                                foreach ( $group_children as $child ) :
                                    $child    = (object) $child;
                                    $child_id = absint( $child->child_id );

                                    $child_answers = $this->get_child_answers( $child_id );
                                    $existing_val  = isset( $child_answers[ $qid ] ) ? $child_answers[ $qid ] : null;
                                    $field_name    = 'answers[' . $child_id . '][' . esc_attr( $qid ) . ']';

                                    $row_class = ( $row_index % 2 === 0 ) ? 'hl-ca-row-even' : 'hl-ca-row-odd';
                                    $row_index++;
                                ?>
                                <?php $is_skipped = $this->is_child_skipped( $child_id ); ?>
                                <tr class="<?php echo esc_attr( $row_class . ( $is_skipped ? ' hl-ca-row-skipped' : '' ) ); ?>" data-child-id="<?php echo absint( $child_id ); ?>">
                                    <td class="hl-ca-child-cell">
                                        <?php echo esc_html( $this->format_child_label( $child ) ); ?>
                                        <?php $dob = $this->format_child_dob( $child ); ?>
                                        <?php if ( $dob ) : ?>
                                            <span class="hl-ca-child-dob">DOB: <?php echo esc_html( $dob ); ?></span>
                                        <?php endif; ?>
                                        <?php $this->render_child_skip_controls( $child_id ); ?>
                                    </td>
                                    <?php foreach ( $allowed_vals as $val ) :
                                        $checked  = ( (string) $existing_val === (string) $val ) ? ' checked' : '';
                                        $input_id = 'hl_' . $child_id . '_' . esc_attr( $qid ) . '_' . sanitize_key( $val );
                                    ?>
                                        <td class="hl-ca-radio-cell">
                                            <input type="radio"
                                                   id="<?php echo esc_attr( $input_id ); ?>"
                                                   name="<?php echo esc_attr( $field_name ); ?>"
                                                   value="<?php echo esc_attr( $val ); ?>"
                                                   class="hl-ca-radio"
                                                   <?php if ( $is_required ) : ?>data-hl-required="1"<?php endif; ?>
                                                   <?php echo $checked; ?>
                                                   <?php echo $is_skipped ? ' disabled' : ''; ?> />
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                <input type="hidden" name="answers[<?php echo absint( $child_id ); ?>][_age_group]" value="<?php echo esc_attr( $group ); ?>" />
                                <input type="hidden" name="answers[<?php echo absint( $child_id ); ?>][_instrument_id]" value="<?php echo esc_attr( $instrument_id ); ?>" />
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                }
                ?>
            </div>
            <?php
        }
    }

    /**
     * Render "Missing a child?" link at the bottom of the form.
     */
    private function render_missing_child_link() {
        $classroom_id = isset( $this->instance['classroom_id'] ) ? absint( $this->instance['classroom_id'] ) : 0;
        if ( ! $classroom_id ) {
            return;
        }

        // Build classroom page URL with return param.
        global $wpdb;
        $page_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'page' AND post_status = 'publish'
             AND post_content LIKE %s LIMIT 1",
            '%[' . $wpdb->esc_like( 'hl_classroom_page' ) . '%'
        ) );

        if ( ! $page_id ) {
            return;
        }

        $classroom_url = add_query_arg( array(
            'id'                    => $classroom_id,
            'return_to_assessment'  => $this->instance_id,
        ), get_permalink( $page_id ) );

        ?>
        <div class="hl-ca-missing-child" style="margin:16px 0; padding:12px 16px; background:var(--hl-bg-secondary, #F9FAFB); border-radius:8px; border:1px dashed var(--hl-border, #E5E7EB);">
            <a href="<?php echo esc_url( $classroom_url ); ?>"
               class="hl-ca-missing-child-link"
               data-instance-id="<?php echo absint( $this->instance_id ); ?>"
               style="font-weight:600; color:var(--hl-secondary, #2C7BE5); text-decoration:none;">
                <?php esc_html_e( 'Missing a child from your classroom?', 'hl-core' ); ?>
            </a>
            <p style="margin:4px 0 0; font-size:13px; color:#6B7280;">
                <?php esc_html_e( 'You can add children to your classroom roster.', 'hl-core' ); ?>
            </p>
        </div>
        <?php
    }

    // ─── Matrix Rendering ────────────────────────────────────────────────

    /**
     * Render a transposed Likert matrix: one column per scale value.
     *
     * Used when the instrument has a single Likert question.
     */
    private function render_transposed_likert_matrix() {
        $question    = $this->questions[0];
        $qid         = $question['question_id'];
        $is_required = ! empty( $question['required'] );

        // Parse allowed values (the actual stored values, e.g. 0,1,2,3,4).
        $allowed_values = $this->parse_allowed_values( $question );
        if ( empty( $allowed_values ) ) {
            $allowed_values = array( '0', '1', '2', '3', '4' );
        }

        $use_labels = $this->is_numeric_likert( $allowed_values );
        ?>
        <table class="hl-ca-matrix">
            <thead>
                <tr>
                    <th class="hl-ca-child-header">&nbsp;</th>
                    <?php foreach ( $allowed_values as $val ) : ?>
                        <th class="hl-ca-scale-header">
                            <?php echo esc_html( $use_labels ? $this->get_likert_label( $val ) : $val ); ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $row_index = 0;
                foreach ( $this->children as $child ) :
                    $child    = (object) $child;
                    $child_id = absint( $child->child_id );

                    $child_answers = $this->get_child_answers( $child_id );
                    $existing_val  = isset( $child_answers[ $qid ] ) ? $child_answers[ $qid ] : null;
                    $field_name    = 'answers[' . $child_id . '][' . esc_attr( $qid ) . ']';

                    $row_class = ( $row_index % 2 === 0 ) ? 'hl-ca-row-even' : 'hl-ca-row-odd';
                    $row_index++;
                ?>
                <?php $is_skipped = $this->is_child_skipped( $child_id ); ?>
                <tr class="<?php echo esc_attr( $row_class . ( $is_skipped ? ' hl-ca-row-skipped' : '' ) ); ?>" data-child-id="<?php echo absint( $child_id ); ?>">
                    <td class="hl-ca-child-cell">
                        <?php echo esc_html( $this->format_child_label( $child ) ); ?>
                        <?php $dob = $this->format_child_dob( $child ); ?>
                        <?php if ( $dob ) : ?>
                            <span class="hl-ca-child-dob">DOB:<?php echo esc_html( $dob ); ?></span>
                        <?php endif; ?>
                        <?php $this->render_child_skip_controls( $child_id ); ?>
                    </td>
                    <?php foreach ( $allowed_values as $val ) :
                        $checked  = ( (string) $existing_val === (string) $val ) ? ' checked' : '';
                        $input_id = 'hl_' . $child_id . '_' . esc_attr( $qid ) . '_' . sanitize_key( $val );
                    ?>
                        <td class="hl-ca-radio-cell">
                            <input type="radio"
                                   id="<?php echo esc_attr( $input_id ); ?>"
                                   name="<?php echo esc_attr( $field_name ); ?>"
                                   value="<?php echo esc_attr( $val ); ?>"
                                   class="hl-ca-radio"
                                   <?php if ( $is_required ) : ?>data-hl-required="1"<?php endif; ?>
                                   <?php echo $checked; ?>
                                   <?php echo $is_skipped ? ' disabled' : ''; ?> />
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render a generic multi-question matrix: one column per question.
     *
     * Used when the instrument has multiple questions or non-Likert types.
     */
    private function render_multi_question_matrix() {
        ?>
        <table class="hl-ca-matrix hl-ca-matrix-multi">
            <thead>
                <tr>
                    <th class="hl-ca-child-header"><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                    <?php foreach ( $this->questions as $question ) : ?>
                        <th class="hl-ca-question-header">
                            <?php echo esc_html( $question['prompt_text'] ); ?>
                            <?php if ( ! empty( $question['required'] ) ) : ?>
                                <span class="hl-ca-required">*</span>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $row_index = 0;
                foreach ( $this->children as $child ) :
                    $child    = (object) $child;
                    $child_id = absint( $child->child_id );
                    $child_answers = $this->get_child_answers( $child_id );
                    $row_class = ( $row_index % 2 === 0 ) ? 'hl-ca-row-even' : 'hl-ca-row-odd';
                    $row_index++;
                ?>
                <tr class="<?php echo esc_attr( $row_class ); ?>">
                    <td class="hl-ca-child-cell">
                        <?php echo esc_html( $this->format_child_label( $child ) ); ?>
                        <?php $dob = $this->format_child_dob( $child ); ?>
                        <?php if ( $dob ) : ?>
                            <span class="hl-ca-child-dob">DOB: <?php echo esc_html( $dob ); ?></span>
                        <?php endif; ?>
                    </td>
                    <?php foreach ( $this->questions as $question ) :
                        $qid          = $question['question_id'];
                        $existing_val = isset( $child_answers[ $qid ] ) ? $child_answers[ $qid ] : null;
                    ?>
                        <td class="hl-ca-input-cell">
                            <?php $this->render_input( $child_id, $question, $existing_val ); ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ─── Input Rendering ─────────────────────────────────────────────────

    /**
     * Render the appropriate input element for a single question + child intersection.
     *
     * @param int   $child_id     The child's ID.
     * @param array $question     The question definition.
     * @param mixed $existing_val The previously saved value (if any).
     */
    private function render_input( $child_id, $question, $existing_val ) {
        $question_id   = $question['question_id'];
        $question_type = isset( $question['question_type'] ) ? $question['question_type'] : ( isset( $question['type'] ) ? $question['type'] : 'text' );
        $is_required   = ! empty( $question['required'] );
        $field_name    = 'answers[' . $child_id . '][' . esc_attr( $question_id ) . ']';
        $allowed_values = $this->parse_allowed_values( $question );
        $req_attr = $is_required ? ' data-hl-required="1"' : '';

        switch ( $question_type ) {

            case 'likert':
                echo '<div class="hl-ca-likert-group"' . $req_attr . '>';
                foreach ( $allowed_values as $val ) {
                    $checked  = ( (string) $existing_val === (string) $val ) ? ' checked' : '';
                    $input_id = 'hl_' . $child_id . '_' . esc_attr( $question_id ) . '_' . sanitize_key( $val );
                    echo '<label class="hl-ca-likert-label" for="' . esc_attr( $input_id ) . '">';
                    echo '<input type="radio"'
                         . ' id="' . esc_attr( $input_id ) . '"'
                         . ' name="' . esc_attr( $field_name ) . '"'
                         . ' value="' . esc_attr( $val ) . '"'
                         . $checked
                         . ' class="hl-ca-radio"'
                         . ' /> ';
                    echo esc_html( $val );
                    echo '</label>';
                }
                echo '</div>';
                break;

            case 'text':
                $val_attr = ( $existing_val !== null ) ? ' value="' . esc_attr( $existing_val ) . '"' : '';
                echo '<input type="text"'
                     . ' name="' . esc_attr( $field_name ) . '"'
                     . $val_attr
                     . ' class="hl-ca-input-text"'
                     . $req_attr
                     . ' />';
                break;

            case 'number':
                $val_attr = ( $existing_val !== null ) ? ' value="' . esc_attr( $existing_val ) . '"' : '';
                echo '<input type="number"'
                     . ' name="' . esc_attr( $field_name ) . '"'
                     . $val_attr
                     . ' class="hl-ca-input-number"'
                     . $req_attr
                     . ' />';
                break;

            case 'single_select':
                echo '<select name="' . esc_attr( $field_name ) . '"'
                     . ' class="hl-ca-input-select"'
                     . $req_attr
                     . '>';
                echo '<option value="">' . esc_html__( '-- Select --', 'hl-core' ) . '</option>';
                foreach ( $allowed_values as $val ) {
                    $selected = ( (string) $existing_val === (string) $val ) ? ' selected' : '';
                    echo '<option value="' . esc_attr( $val ) . '"' . $selected . '>'
                         . esc_html( $val )
                         . '</option>';
                }
                echo '</select>';
                break;

            case 'multi_select':
                $existing_arr = array();
                if ( is_array( $existing_val ) ) {
                    $existing_arr = $existing_val;
                } elseif ( is_string( $existing_val ) && $existing_val !== '' ) {
                    $decoded = json_decode( $existing_val, true );
                    $existing_arr = is_array( $decoded ) ? $decoded : array( $existing_val );
                }
                $ms_field_name = 'answers[' . $child_id . '][' . esc_attr( $question_id ) . '][]';
                echo '<div class="hl-ca-multiselect-group"' . $req_attr . '>';
                foreach ( $allowed_values as $val ) {
                    $checked  = in_array( (string) $val, array_map( 'strval', $existing_arr ), true ) ? ' checked' : '';
                    $input_id = 'hl_' . $child_id . '_' . esc_attr( $question_id ) . '_ms_' . sanitize_key( $val );
                    echo '<label class="hl-ca-multiselect-label" for="' . esc_attr( $input_id ) . '">';
                    echo '<input type="checkbox"'
                         . ' id="' . esc_attr( $input_id ) . '"'
                         . ' name="' . esc_attr( $ms_field_name ) . '"'
                         . ' value="' . esc_attr( $val ) . '"'
                         . $checked
                         . ' class="hl-ca-checkbox"'
                         . ' /> ';
                    echo esc_html( $val );
                    echo '</label>';
                }
                echo '</div>';
                break;

            default:
                $val_attr = ( $existing_val !== null ) ? ' value="' . esc_attr( $existing_val ) . '"' : '';
                echo '<input type="text"'
                     . ' name="' . esc_attr( $field_name ) . '"'
                     . $val_attr
                     . ' class="hl-ca-input-text"'
                     . $req_attr
                     . ' />';
                break;
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Check if the instrument is a single-question Likert assessment (B2E style).
     *
     * @return bool
     */
    private function is_single_question_likert() {
        if ( count( $this->questions ) !== 1 ) {
            return false;
        }
        $q = $this->questions[0];
        $type = isset( $q['question_type'] ) ? $q['question_type'] : ( isset( $q['type'] ) ? $q['type'] : '' );
        return $type === 'likert';
    }

    /**
     * Get age-band-specific behavior key descriptions from the B2E Child Assessment.
     *
     * @param string $age_band One of: infant, toddler, preschool, mixed, k2.
     * @return array Array of scale description arrays.
     */
    private function get_behavior_key_for_age_band( $age_band ) {
        $freq = array(
            'never'         => __( '0% of the time', 'hl-core' ),
            'rarely'        => __( '~ 20% of the time', 'hl-core' ),
            'sometimes'     => __( '~ 50% of the time', 'hl-core' ),
            'usually'       => __( '~ 70% of the time', 'hl-core' ),
            'almost_always' => __( '~ 90% of the time', 'hl-core' ),
        );

        switch ( $age_band ) {
            case 'infant':
                return array(
                    array( 'label' => __( 'Never', 'hl-core' ), 'frequency' => $freq['never'],
                        'description' => __( 'Never notices or responds when other children or caregivers are upset.', 'hl-core' ) ),
                    array( 'label' => __( 'Rarely', 'hl-core' ), 'frequency' => $freq['rarely'],
                        'description' => __( 'Stops to look at another crying infant but rarely responds with concern before going back to what they were doing.', 'hl-core' ) ),
                    array( 'label' => __( 'Sometimes', 'hl-core' ), 'frequency' => $freq['sometimes'],
                        'description' => __( 'Sometimes mirrors the emotions of others by smiling back at caregivers or looking concerned in response to other infants who are crying.', 'hl-core' ) ),
                    array( 'label' => __( 'Usually', 'hl-core' ), 'frequency' => $freq['usually'],
                        'description' => __( 'Usually mirrors the emotions of caregivers and responds when other infants are upset by reaching arms in their direction.', 'hl-core' ) ),
                    array( 'label' => __( 'Almost Always', 'hl-core' ), 'frequency' => $freq['almost_always'],
                        'description' => __( 'Almost always mirrors the emotions of other children and caregivers and attempts to comfort them by reaching out their arms or babbling/cooing.', 'hl-core' ) ),
                );

            case 'preschool':
            case 'mixed':
                return array(
                    array( 'label' => __( 'Never', 'hl-core' ), 'frequency' => $freq['never'],
                        'description' => __( 'Never uses words instead of actions (hitting) to express their feelings or calms down even with caregiver support. Never seems to pick up on or show concern for other people\'s feelings.', 'hl-core' ) ),
                    array( 'label' => __( 'Rarely', 'hl-core' ), 'frequency' => $freq['rarely'],
                        'description' => __( 'Rarely uses words instead of actions to express their feelings or calms down without a lot of caregiver support. Rarely shows concern when friends are upset without guidance.', 'hl-core' ) ),
                    array( 'label' => __( 'Sometimes', 'hl-core' ), 'frequency' => $freq['sometimes'],
                        'description' => __( 'Uses words to express their feelings and sometimes shares what caused them. Sometimes needs a lot of caregiver support to calm down, help others feel better, and solve social problems.', 'hl-core' ) ),
                    array( 'label' => __( 'Usually', 'hl-core' ), 'frequency' => $freq['usually'],
                        'description' => __( 'Usually shares what caused their feelings, manages heightened emotions, notices what others are feeling, and tries to help them feel better or solve the problem with caregiver support.', 'hl-core' ) ),
                    array( 'label' => __( 'Almost Always', 'hl-core' ), 'frequency' => $freq['almost_always'],
                        'description' => __( 'Almost always shares what caused their feelings, calms down with caregiver guidance, notices what others are feeling and tries to help them feel better or solve the problem with support.', 'hl-core' ) ),
                );

            case 'k2':
                return array(
                    array( 'label' => __( 'Never', 'hl-core' ), 'frequency' => $freq['never'],
                        'description' => __( 'Never talks about what they are feeling or finds strategies (deep breaths, physical tools) to calm down independently. Never considers other children\'s feelings and needs help with solving social problems.', 'hl-core' ) ),
                    array( 'label' => __( 'Rarely', 'hl-core' ), 'frequency' => $freq['rarely'],
                        'description' => __( 'Rarely finds strategies to calm down independently and needs a caregiver to offer them choices. Rarely considers other children\'s feelings and needs help with solving social problems.', 'hl-core' ) ),
                    array( 'label' => __( 'Sometimes', 'hl-core' ), 'frequency' => $freq['sometimes'],
                        'description' => __( 'Tries to calm down independently but sometimes needs help with finding strategies. Sometimes considers other children\'s feelings and compromises to solve social problems with guidance.', 'hl-core' ) ),
                    array( 'label' => __( 'Usually', 'hl-core' ), 'frequency' => $freq['usually'],
                        'description' => __( 'Usually manages heightened emotions successfully using a variety of strategies. Considers other children\'s feelings and usually compromises to solve social problems.', 'hl-core' ) ),
                    array( 'label' => __( 'Almost Always', 'hl-core' ), 'frequency' => $freq['almost_always'],
                        'description' => __( 'Almost always manages heightened emotions successfully using a variety of strategies, considers other children\'s feelings, and works with others to compromise and solve social problems.', 'hl-core' ) ),
                );

            case 'toddler':
            default:
                // Toddler is the default since it's the most common age band
                return array(
                    array( 'label' => __( 'Never', 'hl-core' ), 'frequency' => $freq['never'],
                        'description' => __( 'Never expresses their feelings with body language or words or responds to the feelings of others and stays quiet or expressionless instead.', 'hl-core' ) ),
                    array( 'label' => __( 'Rarely', 'hl-core' ), 'frequency' => $freq['rarely'],
                        'description' => __( 'Rarely expresses their feelings with body language or words and hits or throws prolonged temper tantrums instead. Rarely responds to other children who are upset.', 'hl-core' ) ),
                    array( 'label' => __( 'Sometimes', 'hl-core' ), 'frequency' => $freq['sometimes'],
                        'description' => __( 'Sometimes expresses their feelings with body language or words but throws temper tantrums and needs help from caregivers to calm down. Sometimes shows concern if another child cries.', 'hl-core' ) ),
                    array( 'label' => __( 'Usually', 'hl-core' ), 'frequency' => $freq['usually'],
                        'description' => __( 'Usually expresses their feelings with body language or words and recovers from temper tantrums with caregiver support. Notices when others are upset and tries to comfort them.', 'hl-core' ) ),
                    array( 'label' => __( 'Almost Always', 'hl-core' ), 'frequency' => $freq['almost_always'],
                        'description' => __( 'Almost always expresses their feelings with body language or words, recovers quickly from temper tantrums with caregiver support, tries to comfort others, and actively joins in play.', 'hl-core' ) ),
                );
        }
    }

    /**
     * Likert scale label mapping for B2E instruments.
     *
     * The DB stores numeric values (0-4). These map to descriptive labels
     * for display in the matrix column headers and behavior key.
     */
    private static $likert_labels = array(
        '0' => 'Never',
        '1' => 'Rarely',
        '2' => 'Sometimes',
        '3' => 'Usually',
        '4' => 'Almost Always',
    );

    /**
     * Parse allowed_values from a question definition.
     *
     * @param array $question
     * @return array Raw allowed values as stored in DB.
     */
    private function parse_allowed_values( $question ) {
        if ( ! isset( $question['allowed_values'] ) ) {
            return array();
        }
        if ( is_array( $question['allowed_values'] ) ) {
            return $question['allowed_values'];
        }
        if ( is_string( $question['allowed_values'] ) && $question['allowed_values'] !== '' ) {
            return array_map( 'trim', explode( ',', $question['allowed_values'] ) );
        }
        return array();
    }

    /**
     * Check if the allowed_values are numeric B2E Likert values (0-4).
     *
     * @param array $allowed_values
     * @return bool
     */
    private function is_numeric_likert( $allowed_values ) {
        if ( empty( $allowed_values ) ) {
            return false;
        }
        foreach ( $allowed_values as $val ) {
            if ( ! isset( self::$likert_labels[ (string) $val ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the display label for a Likert value.
     *
     * @param string $value Numeric value (0-4).
     * @return string Display label (Never...Almost Always) or the value itself.
     */
    private function get_likert_label( $value ) {
        return isset( self::$likert_labels[ (string) $value ] ) ? self::$likert_labels[ (string) $value ] : $value;
    }

    /**
     * Get decoded answers for a child, handling the nested answers_json structure.
     *
     * @param int $child_id
     * @return array
     */
    private function get_child_answers( $child_id ) {
        if ( ! isset( $this->existing_answers[ $child_id ] ) ) {
            return array();
        }

        $child_answers = $this->existing_answers[ $child_id ];

        if ( isset( $child_answers['answers_json'] ) ) {
            $decoded = $child_answers['answers_json'];
            if ( is_string( $decoded ) ) {
                $decoded = json_decode( $decoded, true );
            }
            return is_array( $decoded ) ? $decoded : array();
        }

        return is_array( $child_answers ) ? $child_answers : array();
    }

    /**
     * Format a child's display label. Uses first + last name, falls back to display code.
     *
     * @param object $child
     * @return string
     */
    private function format_child_label( $child ) {
        $name = trim( ( isset( $child->first_name ) ? $child->first_name : '' ) . ' ' . ( isset( $child->last_name ) ? $child->last_name : '' ) );
        if ( ! empty( $name ) ) {
            return $name;
        }
        if ( ! empty( $child->child_display_code ) ) {
            return $child->child_display_code;
        }
        return __( 'Child', 'hl-core' );
    }

    /**
     * Format a child's DOB for display.
     *
     * @param object $child
     * @return string|null Formatted date or null.
     */
    private function format_child_dob( $child ) {
        if ( empty( $child->dob ) ) {
            return null;
        }
        $timestamp = strtotime( $child->dob );
        if ( $timestamp === false ) {
            return null;
        }
        return date( 'n/j/Y', $timestamp );
    }

    /**
     * Check if a child is marked as skipped in existing draft data.
     *
     * @param int $child_id
     * @return bool
     */
    private function is_child_skipped( $child_id ) {
        $data = isset( $this->existing_answers[ $child_id ] ) ? $this->existing_answers[ $child_id ] : array();
        return ( isset( $data['status'] ) && $data['status'] === 'skipped' );
    }

    /**
     * Render "Not in my classroom" skip controls for a child row.
     *
     * @param int $child_id
     */
    private function render_child_skip_controls( $child_id ) {
        $child_id   = absint( $child_id );
        $is_skipped = $this->is_child_skipped( $child_id );
        $data       = isset( $this->existing_answers[ $child_id ] ) ? $this->existing_answers[ $child_id ] : array();
        $reason     = isset( $data['skip_reason'] ) ? $data['skip_reason'] : '';
        ?>
        <div class="hl-ca-skip-wrap">
            <label class="hl-ca-skip-label">
                <input type="checkbox" class="hl-ca-skip-checkbox" data-child-id="<?php echo $child_id; ?>"<?php echo $is_skipped ? ' checked' : ''; ?> />
                <?php esc_html_e( 'Not in my classroom', 'hl-core' ); ?>
            </label>
            <select class="hl-ca-skip-reason" name="answers[<?php echo $child_id; ?>][_skip_reason]"<?php echo $is_skipped ? '' : ' style="display:none;"'; ?>>
                <option value=""><?php esc_html_e( '-- Reason --', 'hl-core' ); ?></option>
                <option value="left_school"<?php selected( $reason, 'left_school' ); ?>><?php esc_html_e( 'Left school', 'hl-core' ); ?></option>
                <option value="moved_classroom"<?php selected( $reason, 'moved_classroom' ); ?>><?php esc_html_e( 'Moved to another classroom', 'hl-core' ); ?></option>
            </select>
            <input type="hidden" class="hl-ca-skip-flag" name="answers[<?php echo $child_id; ?>][_skip]" value="<?php echo $is_skipped ? '1' : '0'; ?>" />
        </div>
        <?php
    }

    // ─── Inline Styles ───────────────────────────────────────────────────

    /**
     * Render inline CSS for the branded child assessment form.
     */
    private function render_inline_styles() {
        ?>
        <style>
            /* ── Outer Container ─────────────────────────────────── */
            .hl-ca-form-wrap {
                max-width: 900px;
                margin: 0 auto 2em;
                background: var(--hl-surface, #fff);
                border: 1px solid var(--hl-border, #E5E7EB);
                border-radius: var(--hl-radius, 12px);
                box-shadow: var(--hl-shadow, 0 2px 8px rgba(0,0,0,0.06));
                padding: 32px 40px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: var(--hl-text, #374151);
                line-height: 1.6;
            }

            /* ── Branded Header ──────────────────────────────────── */
            .hl-ca-branded-header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 18px;
                border-bottom: 2px solid var(--hl-border-light, #F3F4F6);
            }
            .hl-ca-brand-logo {
                display: block;
                text-align: center;
                margin-bottom: 12px;
            }
            .hl-ca-brand-img {
                max-width: 176px;
                height: auto;
            }
            .hl-ca-title {
                font-size: 22px;
                font-weight: 700;
                color: var(--hl-text-heading, #1A2B47);
                margin: 0;
                line-height: 1.3;
            }
            .hl-ca-phase-label {
                color: var(--hl-text-secondary, #6B7280);
                font-weight: 400;
            }

            /* ── Teacher Info ────────────────────────────────────── */
            .hl-ca-teacher-info {
                margin-bottom: 20px;
                padding-bottom: 16px;
                border-bottom: 1px solid var(--hl-border-light, #F3F4F6);
            }
            .hl-ca-info-row {
                margin-bottom: 4px;
                font-size: 15px;
            }
            .hl-ca-info-label {
                font-weight: 700;
                color: var(--hl-text-heading, #1A2B47);
                margin-right: 6px;
            }
            .hl-ca-info-value {
                color: var(--hl-text, #374151);
            }

            /* ── Instructions ────────────────────────────────────── */
            .hl-ca-instructions {
                margin-bottom: 18px;
            }
            .hl-ca-instructions h3 {
                font-size: 17px;
                font-weight: 700;
                color: var(--hl-text-heading, #1A2B47);
                margin: 0 0 8px 0;
            }
            .hl-ca-instructions p {
                font-size: 14px;
                color: #4B5563;
                margin: 0;
                line-height: 1.7;
            }

            /* ── Behavior Key Table ──────────────────────────────── */
            .hl-ca-behavior-key {
                margin-bottom: 20px;
            }
            table.hl-ca-key-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
                border: 1px solid var(--hl-border, #E5E7EB);
                border-radius: var(--hl-radius-sm, 8px);
                overflow: hidden;
            }
            table.hl-ca-key-table thead th {
                background: var(--hl-primary, #1A2B47);
                color: #fff;
                padding: 12px 16px;
                font-size: 15px;
                font-weight: 600;
                text-align: center;
                letter-spacing: 0.02em;
            }
            table.hl-ca-key-table tbody td {
                padding: 10px 16px;
                border-bottom: 1px solid var(--hl-border-light, #F3F4F6);
                vertical-align: top;
            }
            table.hl-ca-key-table tbody tr:last-child td {
                border-bottom: none;
            }
            .hl-ca-key-label-cell {
                width: 140px;
                min-width: 120px;
                white-space: nowrap;
            }
            .hl-ca-key-label-cell strong {
                display: block;
                color: var(--hl-text-heading, #1A2B47);
                font-size: 14px;
            }
            .hl-ca-key-freq {
                display: block;
                font-size: 12px;
                color: #6B7280;
                margin-top: 2px;
            }
            .hl-ca-key-desc-cell {
                color: var(--hl-text, #374151);
                line-height: 1.5;
            }

            /* ── Question Section ────────────────────────────────── */
            .hl-ca-question-section {
                margin-bottom: 18px;
            }
            .hl-ca-question-section h3 {
                font-size: 16px;
                font-weight: 700;
                color: var(--hl-text-heading, #1A2B47);
                margin: 0 0 6px 0;
            }
            .hl-ca-question-text {
                font-size: 15px;
                color: #1A2B47;
                line-height: 1.6;
                margin: 0;
                font-weight: 600;
            }

            /* ── Age Group Section ───────────────────────────────── */
            .hl-ca-age-group-section {
                margin-bottom: 32px;
                padding-bottom: 16px;
                border-bottom: 2px solid var(--hl-border-light, #F3F4F6);
            }
            .hl-ca-age-group-section:last-of-type {
                border-bottom: none;
                margin-bottom: 0;
            }
            .hl-ca-age-group-header {
                font-size: 18px;
                font-weight: 700;
                color: var(--hl-primary, #1A2B47);
                margin: 24px 0 12px;
                padding: 8px 12px;
                background: var(--hl-bg-alt, #FAFBFC);
                border-radius: var(--hl-radius-sm, 8px);
                border-left: 4px solid var(--hl-secondary, #2C7BE5);
            }

            /* ── Skip Controls ──────────────────────────────────── */
            .hl-ca-skip-wrap {
                margin-top: 4px;
            }
            .hl-ca-skip-label {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                font-size: 11px;
                font-weight: 400;
                color: var(--hl-text-muted, #9CA3AF);
                cursor: pointer;
                white-space: nowrap;
            }
            .hl-ca-skip-label:hover {
                color: var(--hl-error, #EF4444);
            }
            .hl-ca-skip-checkbox {
                width: 14px;
                height: 14px;
                accent-color: var(--hl-error, #EF4444);
                margin: 0;
                cursor: pointer;
            }
            .hl-ca-skip-reason {
                display: block;
                margin-top: 4px;
                font-size: 11px;
                padding: 2px 6px;
                border: 1px solid var(--hl-border-medium, #D1D5DB);
                border-radius: 4px;
                color: var(--hl-text, #374151);
                max-width: 180px;
            }
            tr.hl-ca-row-skipped td {
                opacity: 0.45;
            }
            tr.hl-ca-row-skipped td.hl-ca-child-cell {
                opacity: 1;
            }
            tr.hl-ca-row-skipped td.hl-ca-child-cell .hl-ca-skip-wrap {
                opacity: 1;
            }

            /* ── Matrix Table ────────────────────────────────────── */
            .hl-ca-matrix-wrap {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin-bottom: 8px;
            }
            table.hl-ca-matrix {
                width: 100%;
                border-collapse: collapse;
                font-size: 14px;
                min-width: 500px;
            }
            table.hl-ca-matrix thead th {
                padding: 12px 16px;
                font-weight: 600;
                font-size: 15px;
                color: #374151;
                text-align: center;
                border-bottom: 2px solid var(--hl-border, #E5E7EB);
                white-space: nowrap;
            }
            table.hl-ca-matrix thead th.hl-ca-child-header {
                text-align: left;
                min-width: 180px;
            }
            table.hl-ca-matrix tbody td {
                padding: 10px 16px;
                vertical-align: middle;
                text-align: center;
                border-bottom: 1px solid var(--hl-border-light, #F3F4F6);
            }
            table.hl-ca-matrix tbody td.hl-ca-child-cell {
                text-align: left;
                font-weight: 500;
                color: var(--hl-text-heading, #1A2B47);
                white-space: nowrap;
                min-width: 180px;
            }
            .hl-ca-child-dob {
                display: block;
                font-size: 12px;
                font-weight: 400;
                color: #6B7280;
                margin-top: 1px;
            }

            /* Zebra striping */
            table.hl-ca-matrix tbody tr.hl-ca-row-even td {
                background-color: var(--hl-bg-alt, #FAFBFC);
            }
            table.hl-ca-matrix tbody tr.hl-ca-row-odd td {
                background-color: var(--hl-surface, #fff);
            }
            table.hl-ca-matrix tbody tr:hover td {
                background-color: var(--hl-bg-hover, #EFF6FF);
            }

            /* Radio buttons */
            .hl-ca-radio-cell {
                padding: 10px 8px !important;
            }
            .hl-ca-radio {
                width: 20px;
                height: 20px;
                cursor: pointer;
                accent-color: var(--hl-primary, #1A2B47);
                margin: 0;
            }

            /* Multi-question layout (fallback) */
            .hl-ca-matrix-multi thead th.hl-ca-question-header {
                text-align: left;
                font-size: 13px;
                max-width: 200px;
                white-space: normal;
            }
            .hl-ca-input-cell {
                text-align: left !important;
            }
            .hl-ca-likert-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .hl-ca-likert-label {
                display: flex;
                align-items: center;
                gap: 6px;
                cursor: pointer;
                padding: 2px 0;
                font-size: 13px;
            }
            .hl-ca-multiselect-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .hl-ca-multiselect-label {
                display: flex;
                align-items: center;
                gap: 6px;
                cursor: pointer;
                padding: 2px 0;
                font-size: 13px;
            }
            .hl-ca-input-text,
            .hl-ca-input-number {
                width: 100%;
                max-width: 160px;
                padding: 6px 8px;
                border: 1px solid var(--hl-border-medium, #D1D5DB);
                border-radius: var(--hl-radius-xs, 6px);
                font-size: 14px;
                transition: border-color 0.2s;
            }
            .hl-ca-input-text:focus,
            .hl-ca-input-number:focus,
            .hl-ca-input-select:focus {
                outline: none;
                border-color: var(--hl-secondary, #2C7BE5);
                box-shadow: 0 0 0 3px rgba(44, 123, 229, 0.1);
            }
            .hl-ca-input-select {
                width: 100%;
                max-width: 180px;
                padding: 6px 8px;
                border: 1px solid var(--hl-border-medium, #D1D5DB);
                border-radius: var(--hl-radius-xs, 6px);
                font-size: 14px;
            }
            .hl-ca-required {
                color: var(--hl-error, #EF4444);
                font-weight: bold;
                margin-left: 2px;
            }

            /* ── Action Buttons ──────────────────────────────────── */
            .hl-ca-actions {
                display: flex;
                gap: 12px;
                justify-content: flex-start;
                align-items: center;
                padding: 16px 0 4px;
                border-top: 1px solid var(--hl-border-light, #F3F4F6);
            }
            .hl-ca-btn-draft {
                min-width: 130px;
            }
            .hl-ca-btn-submit {
                min-width: 170px;
                background: var(--hl-primary, #1A2B47) !important;
                color: #fff !important;
                border-color: var(--hl-primary, #1A2B47) !important;
            }
            .hl-ca-btn-submit:hover {
                background: var(--hl-primary-light, #2a3f5f) !important;
                border-color: var(--hl-primary-light, #2a3f5f) !important;
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(26, 43, 71, 0.25);
            }

            /* ── Notice / Warning ────────────────────────────────── */
            .hl-ca-notice {
                padding: 12px 16px;
                border-radius: var(--hl-radius-sm, 8px);
                margin: 1em 0;
            }
            .hl-ca-notice-warning {
                background: #FEF3C7;
                border-left: 4px solid var(--hl-warning, #F59E0B);
                color: #92400E;
            }

            /* ── Validation Error ────────────────────────────────── */
            .hl-ca-validation-error {
                border-color: var(--hl-error, #EF4444) !important;
                box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.15);
            }

            /* ── Read-Only (Submitted) Styles ────────────────────── */
            .hl-ca-form-wrap.hl-ca-readonly table.hl-ca-matrix tbody td {
                font-size: 13px;
            }
            .hl-ca-submitted-banner {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                background: var(--hl-status-complete-bg, #D1FAE5);
                color: var(--hl-status-complete-text, #065F46);
                border-radius: var(--hl-radius-sm, 8px);
                margin-bottom: 24px;
                font-weight: 600;
                font-size: 14px;
            }
            .hl-ca-submitted-banner .hl-ca-submitted-icon {
                font-size: 18px;
            }
            .hl-ca-answer-pill {
                display: inline-block;
                padding: 3px 12px;
                border-radius: var(--hl-radius-pill, 100px);
                background: var(--hl-bg, #F4F5F7);
                color: var(--hl-text, #374151);
                font-size: 13px;
                font-weight: 500;
            }

            /* ── Responsive ──────────────────────────────────────── */
            @media (max-width: 768px) {
                .hl-ca-form-wrap {
                    padding: 24px 20px;
                    margin: 0 -10px 1.5em;
                    border-radius: var(--hl-radius-sm, 8px);
                }
                .hl-ca-title {
                    font-size: 18px;
                }
                .hl-ca-brand-img {
                    max-width: 140px;
                }
                table.hl-ca-key-table {
                    font-size: 13px;
                }
                .hl-ca-key-label-cell {
                    min-width: 90px;
                    width: 100px;
                }
                .hl-ca-actions {
                    flex-direction: column;
                    align-items: stretch;
                }
                .hl-ca-btn-draft,
                .hl-ca-btn-submit {
                    min-width: 0;
                    width: 100%;
                    text-align: center;
                }
                table.hl-ca-matrix thead th {
                    padding: 8px 10px;
                    font-size: 12px;
                }
                table.hl-ca-matrix tbody td {
                    padding: 8px 10px;
                }
                table.hl-ca-matrix tbody td.hl-ca-child-cell {
                    min-width: 140px;
                    font-size: 13px;
                }
            }
        </style>
        <?php
    }

    // ─── Inline Script ───────────────────────────────────────────────────

    /**
     * Render inline JavaScript for validation toggle between draft and submit.
     */
    private function render_inline_script() {
        $instance_id = esc_js( $this->instance_id );
        ?>
        <script>
        (function() {
            var formId        = 'hl-ca-form-<?php echo $instance_id; ?>';
            var hiddenFieldId = 'hl-ca-requires-validation-<?php echo $instance_id; ?>';
            var draftBtnId    = 'hl-ca-btn-draft-<?php echo $instance_id; ?>';
            var submitBtnId   = 'hl-ca-btn-submit-<?php echo $instance_id; ?>';

            var form        = document.getElementById(formId);
            var hiddenField = document.getElementById(hiddenFieldId);
            var draftBtn    = document.getElementById(draftBtnId);
            var submitBtn   = document.getElementById(submitBtnId);

            if (!form || !hiddenField || !draftBtn || !submitBtn) {
                return;
            }

            function setValidation(enable) {
                hiddenField.value = enable ? '1' : '0';

                // Direct inputs/selects with data-hl-required.
                var directFields = form.querySelectorAll('[data-hl-required="1"]');
                directFields.forEach(function(el) {
                    var tagName = el.tagName.toLowerCase();
                    if (tagName === 'input' || tagName === 'select' || tagName === 'textarea') {
                        if (enable) {
                            el.setAttribute('required', 'required');
                        } else {
                            el.removeAttribute('required');
                        }
                    }

                    // For radio groups
                    var radios = el.querySelectorAll('input[type="radio"]');
                    radios.forEach(function(radio) {
                        if (enable) {
                            radio.setAttribute('required', 'required');
                        } else {
                            radio.removeAttribute('required');
                        }
                    });

                    // For multi_select checkboxes
                    var checkboxes = el.querySelectorAll('input[type="checkbox"]');
                    if (checkboxes.length > 0) {
                        if (enable) {
                            var anyChecked = Array.prototype.some.call(checkboxes, function(cb) {
                                return cb.checked;
                            });
                            if (!anyChecked) {
                                checkboxes[0].setAttribute('required', 'required');
                            }
                        } else {
                            checkboxes.forEach(function(cb) {
                                cb.removeAttribute('required');
                            });
                        }
                    }
                });

                // For the transposed Likert layout, radio inputs have data-hl-required directly.
                var requiredRadios = form.querySelectorAll('input[type="radio"][data-hl-required="1"]');
                requiredRadios.forEach(function(radio) {
                    if (enable) {
                        radio.setAttribute('required', 'required');
                    } else {
                        radio.removeAttribute('required');
                    }
                });
            }

            // Draft: disable validation.
            draftBtn.addEventListener('click', function(e) {
                setValidation(false);
            });

            // Submit: enable validation, confirm dialog.
            submitBtn.addEventListener('click', function(e) {
                if (!confirm('<?php echo esc_js( __( 'Once submitted, answers cannot be changed. Continue?', 'hl-core' ) ); ?>')) {
                    e.preventDefault();
                    return;
                }
                setValidation(true);
            });

            // Handle multi_select required toggling.
            form.addEventListener('change', function(e) {
                if (e.target.type !== 'checkbox') return;
                if (hiddenField.value !== '1') return;

                var group = e.target.closest('.hl-ca-multiselect-group[data-hl-required="1"]');
                if (!group) return;

                var checkboxes = group.querySelectorAll('input[type="checkbox"]');
                var anyChecked = Array.prototype.some.call(checkboxes, function(cb) {
                    return cb.checked;
                });
                checkboxes.forEach(function(cb) {
                    cb.removeAttribute('required');
                });
                if (!anyChecked) {
                    checkboxes[0].setAttribute('required', 'required');
                }
            });

            // "Not in my classroom" skip checkbox toggle.
            var skipCheckboxes = form ? form.querySelectorAll('.hl-ca-skip-checkbox') : [];
            skipCheckboxes.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var childId = cb.getAttribute('data-child-id');
                    var row = form.querySelector('tr[data-child-id="' + childId + '"]');
                    if (!row) return;

                    var radios     = row.querySelectorAll('input.hl-ca-radio');
                    var skipFlag   = form.querySelector('.hl-ca-skip-flag[name="answers[' + childId + '][_skip]"]');
                    var skipReason = form.querySelector('.hl-ca-skip-reason[name="answers[' + childId + '][_skip_reason]"]');

                    if (cb.checked) {
                        row.classList.add('hl-ca-row-skipped');
                        radios.forEach(function(r) { r.disabled = true; });
                        if (skipFlag) skipFlag.value = '1';
                        if (skipReason) skipReason.style.display = '';
                    } else {
                        row.classList.remove('hl-ca-row-skipped');
                        radios.forEach(function(r) { r.disabled = false; });
                        if (skipFlag) skipFlag.value = '0';
                        if (skipReason) { skipReason.style.display = 'none'; skipReason.value = ''; }
                    }
                });
            });

            // "Missing a child?" link — auto-save draft via AJAX before navigating.
            var missingLink = form ? form.closest('.hl-ca-form-wrap') : null;
            missingLink = missingLink ? missingLink.querySelector('.hl-ca-missing-child-link') : null;

            if (missingLink && form) {
                missingLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    var targetUrl = missingLink.href;

                    // Collect form data for AJAX draft save.
                    var formData = new FormData(form);
                    formData.set('action', 'hl_save_assessment_draft');
                    formData.set('hl_assessment_action', 'draft');

                    fetch(
                        (typeof hlCore !== 'undefined' && hlCore.ajaxUrl) ? hlCore.ajaxUrl : '/wp-admin/admin-ajax.php',
                        { method: 'POST', body: formData, credentials: 'same-origin' }
                    )
                    .then(function() {
                        window.location.href = targetUrl;
                    })
                    .catch(function() {
                        if (confirm('<?php echo esc_js( __( 'Draft could not be saved. Continue anyway?', 'hl-core' ) ); ?>')) {
                            window.location.href = targetUrl;
                        }
                    });
                });
            }

        })();
        </script>
        <?php
    }
}
