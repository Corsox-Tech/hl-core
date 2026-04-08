<?php
if (!defined('ABSPATH')) exit;

/**
 * Renders a teacher self-assessment instrument as an HTML form.
 *
 * Supports two modes:
 *  - PRE: Single-column layout — teacher rates each item once.
 *  - POST: Dual-column retrospective — "Before Program" (pre-filled from PRE,
 *    read-only) and "Now" (teacher fills in current rating).
 *
 * Section types supported: likert (radio scale) and scale (0–10 numeric radios).
 *
 * @package HL_Core
 * @since   1.0.0
 */
class HL_Teacher_Assessment_Renderer {

    /** @var HL_Teacher_Assessment_Instrument */
    private $instrument;

    /** @var object Instance row (instance_id, status, phase, etc.) */
    private $instance;

    /** @var string 'pre' or 'post' */
    private $phase;

    /** @var array Decoded existing responses for this instance (draft resume). */
    private $existing_responses;

    /** @var array Decoded PRE responses for pre-filling POST "Before" column. */
    private $pre_responses;

    /** @var bool Whether to render in read-only mode (submitted view). */
    private $read_only;

    /** @var array Display options (show_instrument_name, show_program_name, program_name). */
    private $display_options;

    /**
     * Constructor.
     *
     * @param HL_Teacher_Assessment_Instrument $instrument         Instrument definition.
     * @param object                           $instance           Instance row.
     * @param string                           $phase              'pre' or 'post'.
     * @param array                            $existing_responses Decoded responses_json for this instance.
     * @param array                            $pre_responses      Decoded PRE responses (only used for POST phase).
     * @param bool                             $read_only          Whether the form is read-only.
     * @param array                            $display_options    Optional display overrides.
     */
    public function __construct( $instrument, $instance, $phase, $existing_responses = array(), $pre_responses = array(), $read_only = false, $display_options = array() ) {
        $this->instrument         = $instrument;
        $this->instance           = (object) $instance;
        $this->phase              = $phase;
        $this->existing_responses = is_array( $existing_responses ) ? $existing_responses : array();
        $this->pre_responses      = is_array( $pre_responses ) ? $pre_responses : array();
        $this->read_only          = $read_only;
        $this->display_options    = wp_parse_args( $display_options, array(
            'show_instrument_name' => true,
            'show_program_name'    => false,
            'program_name'         => '',
        ) );
    }

    /**
     * Render the full teacher assessment form.
     *
     * @return string HTML output.
     */
    public function render() {
        $sections     = $this->instrument->get_sections();
        $scale_labels = $this->instrument->get_scale_labels();

        if ( empty( $sections ) ) {
            ob_start();
            ?>
            <div class="hl-tsa-notice">
                <p><?php esc_html_e( 'No sections configured for this instrument.', 'hl-core' ); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        $instance_id    = absint( $this->instance->instance_id );
        $total_sections = count( $sections );
        $paginate       = ! $this->read_only && $total_sections > 1;

        ob_start();

        $this->render_inline_styles();

        // Phase label
        $phase_label = ( $this->phase === 'post' )
            ? __( 'Post-Program Self-Assessment', 'hl-core' )
            : __( 'Pre-Program Self-Assessment', 'hl-core' );

        ?>
        <div class="hl-tsa-form-wrap">
            <div class="hl-tsa-header">
                <h2><?php echo esc_html( $this->instrument->instrument_name ); ?></h2>
                <?php if ( $this->display_options['show_program_name'] && ! empty( $this->display_options['program_name'] ) ) : ?>
                    <span class="hl-tsa-program-name"><?php echo esc_html( $this->display_options['program_name'] ); ?></span>
                <?php endif; ?>
                <span class="hl-tsa-phase-badge hl-tsa-phase-<?php echo esc_attr( $this->phase ); ?>">
                    <?php echo esc_html( $phase_label ); ?>
                </span>
            </div>

            <?php
            // Instrument-level instructions (stored in DB)
            $instructions = $this->instrument->get_instructions();
            if ( ! empty( $instructions ) ) : ?>
                <div class="hl-tsa-instructions">
                    <?php echo wp_kses_post( $instructions ); ?>
                </div>
            <?php endif; ?>


        <?php if ( ! $this->read_only ) : ?>
            <form method="post" action="" class="hl-tsa-form<?php echo $paginate ? ' hl-tsa-form--paginated' : ''; ?>" id="hl-tsa-form-<?php echo esc_attr( $instance_id ); ?>">
                <?php wp_nonce_field( 'hl_save_teacher_assessment', 'hl_teacher_assessment_nonce' ); ?>
                <input type="hidden" name="hl_tsa_instance_id" value="<?php echo esc_attr( $instance_id ); ?>" />
                <input type="hidden" name="hl_tsa_phase" value="<?php echo esc_attr( $this->phase ); ?>" />
                <input type="hidden" name="hl_requires_validation" id="hl_tsa_requires_validation_<?php echo esc_attr( $instance_id ); ?>" value="0" />

                <?php if ( $paginate ) : ?>
                <div class="hl-tsa-step-indicator">
                    <span class="hl-tsa-step-label"><?php
                        /* translators: %1$s: current section number (with markup), %2$s: total sections */
                        printf( 'Section %1$s of %2$s', '<span class="hl-tsa-step-current">1</span>', esc_html( $total_sections ) );
                    ?></span>
                    <div class="hl-tsa-step-dots">
                        <?php for ( $si = 0; $si < $total_sections; $si++ ) : ?>
                            <span class="hl-tsa-step<?php echo $si === 0 ? ' hl-tsa-step--active' : ''; ?>" data-step="<?php echo esc_attr( $si ); ?>"><?php echo esc_html( $si + 1 ); ?></span>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endif; ?>

        <?php endif; ?>

                <?php
                $step_index = 0;
                foreach ( $sections as $section ) {
                    $this->render_section( $section, $scale_labels, $step_index, $total_sections, $paginate );
                    $step_index++;
                }
                ?>

        <?php if ( ! $this->read_only ) : ?>
                <div class="hl-tsa-actions">
                    <button type="submit"
                            name="hl_tsa_action"
                            value="draft"
                            class="button button-secondary hl-btn-save-draft"
                            id="hl-tsa-btn-draft-<?php echo esc_attr( $instance_id ); ?>">
                        <?php esc_html_e( 'Save Draft', 'hl-core' ); ?>
                    </button>
                    <button type="submit"
                            name="hl_tsa_action"
                            value="submit"
                            class="button button-primary hl-btn-submit-assessment"
                            id="hl-tsa-btn-submit-<?php echo esc_attr( $instance_id ); ?>">
                        <?php esc_html_e( 'Submit Assessment', 'hl-core' ); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>

        </div>

        <?php if ( ! $this->read_only ) : ?>
            <div class="hl-tsa-skip-overlay" id="hl-tsa-skip-overlay-<?php echo esc_attr( $instance_id ); ?>" style="display: none;">
                <div class="hl-tsa-skip-modal">
                    <p class="hl-tsa-skip-bold"><?php esc_html_e( 'You have unanswered items in this page.', 'hl-core' ); ?></p>
                    <p class="hl-tsa-skip-detail"><?php esc_html_e( 'Select "Return" to complete the missing items. Continue to "Submit" if you prefer not to answer.', 'hl-core' ); ?></p>
                    <div class="hl-tsa-skip-buttons">
                        <button type="button" class="button hl-tsa-skip-return"><?php esc_html_e( 'Return', 'hl-core' ); ?></button>
                        <button type="button" class="button button-primary hl-tsa-skip-submit"><?php esc_html_e( 'Submit', 'hl-core' ); ?></button>
                    </div>
                </div>
            </div>
            <?php $this->render_inline_script( $instance_id ); ?>
        <?php endif; ?>

        <?php
        return ob_get_clean();
    }

    // ─── Section rendering ──────────────────────────────────────────────

    /**
     * Render a single section (dispatches by type).
     */
    private function render_section( $section, $scale_labels, $step_index = 0, $total_sections = 1, $paginate = false ) {
        $section_key = isset( $section['section_key'] ) ? $section['section_key'] : '';
        $title       = isset( $section['title'] ) ? $section['title'] : '';
        $description = isset( $section['description'] ) ? $section['description'] : '';
        $type        = isset( $section['type'] ) ? $section['type'] : 'likert';
        $scale_key   = isset( $section['scale_key'] ) ? $section['scale_key'] : '';
        $items       = isset( $section['items'] ) ? $section['items'] : array();
        $labels      = isset( $scale_labels[ $scale_key ] ) ? $scale_labels[ $scale_key ] : array();

        $active_class = ( $paginate && $step_index === 0 ) ? ' hl-tsa-section--active' : '';
        $is_first     = ( $step_index === 0 );
        $is_last      = ( $step_index === $total_sections - 1 );

        ?>
        <div class="hl-tsa-section<?php echo esc_attr( $active_class ); ?>" data-section="<?php echo esc_attr( $section_key ); ?>" data-step="<?php echo esc_attr( $step_index ); ?>">
            <h3 class="hl-tsa-section-title"><?php echo esc_html( $title ); ?></h3>
            <?php if ( $description ) : ?>
                <div class="hl-tsa-section-desc"><?php echo wp_kses_post( $description ); ?></div>
            <?php endif; ?>

            <?php
            $retrospective = ! empty( $section['retrospective'] );
            if ( $type === 'text' ) {
                $this->render_text_section( $section_key, $items );
            } elseif ( $type === 'scale' ) {
                $this->render_scale_section( $section_key, $items, $labels, $retrospective );
            } else {
                $this->render_likert_section( $section_key, $items, $labels, $retrospective, $section );
            }
            ?>

            <?php if ( $paginate && ! $this->read_only ) : ?>
            <div class="hl-tsa-nav">
                <?php if ( ! $is_first ) : ?>
                    <button type="button" class="button hl-tsa-btn-prev"><?php esc_html_e( 'Previous Section', 'hl-core' ); ?></button>
                <?php endif; ?>
                <?php if ( ! $is_last ) : ?>
                    <button type="submit" name="hl_tsa_action" value="draft" class="button hl-btn-save-draft hl-tsa-nav-draft"><?php esc_html_e( 'Save Draft', 'hl-core' ); ?></button>
                <?php endif; ?>
                <?php if ( ! $is_last ) : ?>
                    <button type="button" class="button button-primary hl-tsa-btn-next"><?php esc_html_e( 'Next Section', 'hl-core' ); ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a likert section.
     *
     * PRE: single-column table with radio buttons.
     * POST: dual-column table (Before + Now).
     */
    private function render_likert_section( $section_key, $items, $labels, $retrospective = false, $section = array() ) {
        // Labels is an indexed array like ["Strongly Disagree", ..., "Strongly Agree"]
        $num_options = count( $labels );
        if ( $num_options < 2 ) {
            $num_options = 5;
            $labels = array( '1', '2', '3', '4', '5' );
        }

        $is_post = $retrospective;

        // Custom retrospective column headers (fall back to defaults).
        $before_label = ! empty( $section['before_label'] ) ? $section['before_label'] : __( 'Prior Assessment Cycle', 'hl-core' );
        $now_label    = ! empty( $section['now_label'] )    ? $section['now_label']    : __( 'Past Two Weeks', 'hl-core' );

        ?>
        <div class="hl-tsa-table-wrap">
            <table class="hl-tsa-likert-table">
                <thead>
                    <tr>
                        <th class="hl-tsa-item-col"><?php esc_html_e( 'Statement', 'hl-core' ); ?></th>
                        <?php if ( $is_post ) : ?>
                            <th class="hl-tsa-group-header" colspan="<?php echo esc_attr( $num_options ); ?>">
                                <?php echo esc_html( $before_label ); ?>
                            </th>
                            <th class="hl-tsa-group-header hl-tsa-now-header" colspan="<?php echo esc_attr( $num_options ); ?>">
                                <?php echo esc_html( $now_label ); ?>
                            </th>
                        <?php else : ?>
                            <?php foreach ( $labels as $label ) : ?>
                                <th class="hl-tsa-label-col"><span class="hl-tsa-label-text"><?php echo esc_html( $label ); ?></span></th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                    <?php if ( $is_post ) : ?>
                    <tr class="hl-tsa-sublabel-row">
                        <th></th>
                        <?php foreach ( $labels as $label ) : ?>
                            <th class="hl-tsa-label-col hl-tsa-before-label"><span class="hl-tsa-label-text"><?php echo esc_html( $label ); ?></span></th>
                        <?php endforeach; ?>
                        <?php foreach ( $labels as $label ) : ?>
                            <th class="hl-tsa-label-col hl-tsa-now-label"><span class="hl-tsa-label-text"><?php echo esc_html( $label ); ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php
                    $row_idx = 0;
                    foreach ( $items as $item ) :
                        $item_key    = isset( $item['key'] ) ? $item['key'] : '';
                        $item_text   = isset( $item['text'] ) ? $item['text'] : '';
                        $row_class   = ( $row_idx % 2 === 0 ) ? 'hl-tsa-row-even' : 'hl-tsa-row-odd';
                        $row_idx++;

                        // Current instance response for this item
                        $current_val = isset( $this->existing_responses[ $section_key ][ $item_key ] ) ? $this->existing_responses[ $section_key ][ $item_key ] : null;
                        // PRE response for POST "Before" column
                        $pre_val     = isset( $this->pre_responses[ $section_key ][ $item_key ] ) ? $this->pre_responses[ $section_key ][ $item_key ] : null;
                    ?>
                    <tr class="<?php echo esc_attr( $row_class ); ?>">
                        <td class="hl-tsa-item-cell"><?php echo wp_kses_post( $item_text ); ?></td>
                        <?php if ( $is_post ) : ?>
                            <?php // Before column (pre-filled, disabled) ?>
                            <?php for ( $i = 0; $i < $num_options; $i++ ) :
                                $val = (string) $i;
                                $checked = ( (string) $pre_val === $val ) ? ' checked' : '';
                            ?>
                                <td class="hl-tsa-radio-cell hl-tsa-before-col">
                                    <input type="radio"
                                           disabled
                                           <?php echo $checked; ?>
                                           class="hl-tsa-radio-disabled" />
                                </td>
                            <?php endfor; ?>

                            <?php // Now column (active) ?>
                            <?php
                            // For POST, current_val may be nested as ['now' => X]
                            $now_val = null;
                            if ( is_array( $current_val ) && isset( $current_val['now'] ) ) {
                                $now_val = $current_val['now'];
                            } elseif ( ! is_array( $current_val ) ) {
                                $now_val = $current_val;
                            }
                            ?>
                            <?php for ( $i = 0; $i < $num_options; $i++ ) :
                                $val = (string) $i;
                                $checked = ( (string) $now_val === $val ) ? ' checked' : '';
                                $name = 'resp[' . esc_attr( $section_key ) . '][' . esc_attr( $item_key ) . '][now]';
                                $input_id = 'hl_tsa_' . $section_key . '_' . $item_key . '_now_' . $val;
                            ?>
                                <td class="hl-tsa-radio-cell hl-tsa-now-col">
                                    <?php if ( $this->read_only ) : ?>
                                        <input type="radio" disabled <?php echo $checked; ?> class="hl-tsa-radio-disabled" />
                                    <?php else : ?>
                                        <input type="radio"
                                               name="<?php echo esc_attr( $name ); ?>"
                                               id="<?php echo esc_attr( $input_id ); ?>"
                                               value="<?php echo esc_attr( $val ); ?>"
                                               <?php echo $checked; ?>
                                               data-hl-required="1"
                                               class="hl-tsa-radio" />
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        <?php else : ?>
                            <?php // PRE mode: single column ?>
                            <?php for ( $i = 0; $i < $num_options; $i++ ) :
                                $val = (string) $i;
                                $checked = ( (string) $current_val === $val ) ? ' checked' : '';
                                $name = 'resp[' . esc_attr( $section_key ) . '][' . esc_attr( $item_key ) . ']';
                                $input_id = 'hl_tsa_' . $section_key . '_' . $item_key . '_' . $val;
                            ?>
                                <td class="hl-tsa-radio-cell">
                                    <?php if ( $this->read_only ) : ?>
                                        <input type="radio" disabled <?php echo $checked; ?> class="hl-tsa-radio-disabled" />
                                    <?php else : ?>
                                        <input type="radio"
                                               name="<?php echo esc_attr( $name ); ?>"
                                               id="<?php echo esc_attr( $input_id ); ?>"
                                               value="<?php echo esc_attr( $val ); ?>"
                                               <?php echo $checked; ?>
                                               data-hl-required="1"
                                               class="hl-tsa-radio" />
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render a 0–10 scale section.
     *
     * PRE: horizontal 0–10 radios per item.
     * POST: dual-column (Before disabled + Now active).
     */
    private function render_scale_section( $section_key, $items, $labels, $retrospective = false ) {
        $global_low  = isset( $labels['low'] ) ? $labels['low'] : '0';
        $global_high = isset( $labels['high'] ) ? $labels['high'] : '10';
        $is_post     = $retrospective;

        ?>
        <div class="hl-tsa-scale-section">

            <?php
            $row_idx = 0;
            foreach ( $items as $item ) :
                $item_key  = isset( $item['key'] ) ? $item['key'] : '';
                $item_text = isset( $item['text'] ) ? $item['text'] : '';
                $row_class = ( $row_idx % 2 === 0 ) ? 'hl-tsa-row-even' : 'hl-tsa-row-odd';
                $row_idx++;

                // Per-item anchors (fallback to global section anchors)
                $low_label  = isset( $item['left_anchor'] ) ? $item['left_anchor'] : $global_low;
                $high_label = isset( $item['right_anchor'] ) ? $item['right_anchor'] : $global_high;

                $current_val = isset( $this->existing_responses[ $section_key ][ $item_key ] ) ? $this->existing_responses[ $section_key ][ $item_key ] : null;
                $pre_val     = isset( $this->pre_responses[ $section_key ][ $item_key ] ) ? $this->pre_responses[ $section_key ][ $item_key ] : null;
            ?>
            <div class="hl-tsa-scale-row <?php echo esc_attr( $row_class ); ?>">
                <div class="hl-tsa-scale-text"><?php echo wp_kses_post( $item_text ); ?></div>
                <div class="hl-tsa-scale-anchors">
                    <span class="hl-tsa-anchor-low">0 = <?php echo esc_html( $low_label ); ?></span>
                    <span class="hl-tsa-anchor-high">10 = <?php echo esc_html( $high_label ); ?></span>
                </div>

                <?php if ( $is_post ) : ?>
                    <div class="hl-tsa-scale-dual">
                        <div class="hl-tsa-scale-group hl-tsa-before-group">
                            <span class="hl-tsa-group-label"><?php esc_html_e( 'Prior Assessment Cycle', 'hl-core' ); ?></span>
                            <div class="hl-tsa-scale-radios">
                                <?php for ( $v = 0; $v <= 10; $v++ ) :
                                    $checked = ( (string) $pre_val === (string) $v ) ? ' checked' : '';
                                ?>
                                    <label class="hl-tsa-scale-label">
                                        <input type="radio" disabled <?php echo $checked; ?> class="hl-tsa-radio-disabled" />
                                        <span><?php echo esc_html( $v ); ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="hl-tsa-scale-group hl-tsa-now-group">
                            <span class="hl-tsa-group-label"><?php esc_html_e( 'Past Two Weeks', 'hl-core' ); ?></span>
                            <div class="hl-tsa-scale-radios">
                                <?php
                                $now_val = null;
                                if ( is_array( $current_val ) && isset( $current_val['now'] ) ) {
                                    $now_val = $current_val['now'];
                                } elseif ( ! is_array( $current_val ) ) {
                                    $now_val = $current_val;
                                }
                                $name = 'resp[' . esc_attr( $section_key ) . '][' . esc_attr( $item_key ) . '][now]';
                                ?>
                                <?php for ( $v = 0; $v <= 10; $v++ ) :
                                    $checked  = ( (string) $now_val === (string) $v ) ? ' checked' : '';
                                    $input_id = 'hl_tsa_' . $section_key . '_' . $item_key . '_now_' . $v;
                                ?>
                                    <label class="hl-tsa-scale-label" for="<?php echo esc_attr( $input_id ); ?>">
                                        <?php if ( $this->read_only ) : ?>
                                            <input type="radio" disabled <?php echo $checked; ?> class="hl-tsa-radio-disabled" />
                                        <?php else : ?>
                                            <input type="radio"
                                                   name="<?php echo esc_attr( $name ); ?>"
                                                   id="<?php echo esc_attr( $input_id ); ?>"
                                                   value="<?php echo esc_attr( $v ); ?>"
                                                   <?php echo $checked; ?>
                                                   data-hl-required="1"
                                                   class="hl-tsa-radio" />
                                        <?php endif; ?>
                                        <span><?php echo esc_html( $v ); ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="hl-tsa-scale-radios">
                        <?php
                        $name = 'resp[' . esc_attr( $section_key ) . '][' . esc_attr( $item_key ) . ']';
                        ?>
                        <?php for ( $v = 0; $v <= 10; $v++ ) :
                            $checked  = ( (string) $current_val === (string) $v ) ? ' checked' : '';
                            $input_id = 'hl_tsa_' . $section_key . '_' . $item_key . '_' . $v;
                        ?>
                            <label class="hl-tsa-scale-label" for="<?php echo esc_attr( $input_id ); ?>">
                                <?php if ( $this->read_only ) : ?>
                                    <input type="radio" disabled <?php echo $checked; ?> class="hl-tsa-radio-disabled" />
                                <?php else : ?>
                                    <input type="radio"
                                           name="<?php echo esc_attr( $name ); ?>"
                                           id="<?php echo esc_attr( $input_id ); ?>"
                                           value="<?php echo esc_attr( $v ); ?>"
                                           <?php echo $checked; ?>
                                           data-hl-required="1"
                                           class="hl-tsa-radio" />
                                <?php endif; ?>
                                <span><?php echo esc_html( $v ); ?></span>
                            </label>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a text (open-ended) section with textarea inputs.
     */
    private function render_text_section( $section_key, $items ) {
        ?>
        <div class="hl-tsa-text-section">
            <?php foreach ( $items as $item ) :
                $item_key    = isset( $item['key'] ) ? $item['key'] : '';
                $current_val = isset( $this->existing_responses[ $section_key ][ $item_key ] )
                    ? $this->existing_responses[ $section_key ][ $item_key ] : '';
                $name        = 'resp[' . esc_attr( $section_key ) . '][' . esc_attr( $item_key ) . ']';
            ?>
                <div class="hl-tsa-text-item">
                    <label class="hl-tsa-text-label" for="<?php echo esc_attr( 'hl_tsa_' . $section_key . '_' . $item_key ); ?>">
                        <?php echo esc_html( $item['text'] ); ?>
                    </label>
                    <?php if ( $this->read_only ) : ?>
                        <div class="hl-tsa-text-readonly"><?php echo nl2br( esc_html( $current_val ) ); ?></div>
                    <?php else : ?>
                        <textarea
                            name="<?php echo esc_attr( $name ); ?>"
                            id="<?php echo esc_attr( 'hl_tsa_' . $section_key . '_' . $item_key ); ?>"
                            class="hl-tsa-textarea"
                            rows="4"
                        ><?php echo esc_textarea( $current_val ); ?></textarea>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ─── Inline styles ──────────────────────────────────────────────────

    private function render_inline_styles() {
        // Static CSS moved to frontend.css under "FORMS & INSTRUMENTS (Session 2)"
        // Only admin-customizable overrides remain inline.
        ?>
        <style>
            /* ── Admin-customizable style overrides ─────────── */
            <?php $this->render_style_overrides(); ?>
        </style>
        <?php
    }

    /**
     * Emit CSS overrides from the instrument's styles_json.
     */
    private function render_style_overrides() {
        $styles = $this->instrument->get_styles();
        if ( empty( $styles ) ) {
            return;
        }

        // Prefix selectors with wrapper class for high specificity (beats BuddyBoss/Elementor global styles).
        $w = '.hl-tsa-form-wrap';
        $map = array(
            'instructions_font_size'  => array( "{$w} .hl-tsa-instructions, {$w} .hl-tsa-instructions p", 'font-size' ),
            'instructions_color'      => array( "{$w} .hl-tsa-instructions, {$w} .hl-tsa-instructions p", 'color' ),
            'section_title_font_size' => array( "{$w} .hl-tsa-section-title", 'font-size' ),
            'section_title_color'     => array( "{$w} .hl-tsa-section-title", 'color' ),
            'section_desc_font_size'  => array( "{$w} .hl-tsa-section-desc, {$w} .hl-tsa-section-desc p", 'font-size' ),
            'section_desc_color'      => array( "{$w} .hl-tsa-section-desc, {$w} .hl-tsa-section-desc p", 'color' ),
            'item_font_size'          => array( "{$w} table.hl-tsa-likert-table .hl-tsa-item-cell, {$w} .hl-tsa-scale-text", 'font-size' ),
            'item_color'              => array( "{$w} table.hl-tsa-likert-table .hl-tsa-item-cell, {$w} .hl-tsa-scale-text", 'color' ),
            'scale_label_font_size'   => array( "{$w} table.hl-tsa-likert-table thead th, {$w} table.hl-tsa-likert-table .hl-tsa-label-col", 'font-size' ),
            'scale_label_color'       => array( "{$w} table.hl-tsa-likert-table thead th, {$w} table.hl-tsa-likert-table .hl-tsa-label-col", 'color' ),
        );

        foreach ( $map as $key => $rule ) {
            if ( ! empty( $styles[ $key ] ) ) {
                $selector = $rule[0];
                $property = $rule[1];
                $value    = esc_attr( $styles[ $key ] );
                echo "{$selector} { {$property}: {$value} !important; }\n";
            }
        }
    }

    // ─── Inline script ──────────────────────────────────────────────────

    /**
     * Render inline JavaScript for draft/submit validation toggle.
     */
    private function render_inline_script( $instance_id ) {
        $esc_id = esc_js( $instance_id );
        ?>
        <script>
        (function() {
            var formId        = 'hl-tsa-form-<?php echo $esc_id; ?>';
            var hiddenFieldId = 'hl_tsa_requires_validation_<?php echo $esc_id; ?>';
            var submitBtnId   = 'hl-tsa-btn-submit-<?php echo $esc_id; ?>';

            var form        = document.getElementById(formId);
            var hiddenField = document.getElementById(hiddenFieldId);
            var submitBtn   = document.getElementById(submitBtnId);

            if (!form || !hiddenField) return;

            /**
             * Custom validation for paginated forms.
             * Native HTML5 required validation silently fails on hidden (display:none) sections,
             * so we check manually and navigate to the first section with missing answers.
             */
            function findMissingRequiredGroups() {
                return findMissingInContainer(form);
            }

            /**
             * Find unanswered required radio groups within a specific container element.
             */
            function findMissingInContainer(container) {
                var radios = container.querySelectorAll('input[type="radio"][data-hl-required="1"]');
                var groups = {};
                radios.forEach(function(r) {
                    if (!groups[r.name]) groups[r.name] = [];
                    groups[r.name].push(r);
                });

                var missing = [];
                Object.keys(groups).forEach(function(name) {
                    var answered = groups[name].some(function(r) { return r.checked; });
                    if (!answered) {
                        missing.push(groups[name][0]);
                    }
                });
                return missing;
            }

            /**
             * Highlight the first missing answer in a container and show an alert.
             * Returns true if there are missing answers.
             */
            function highlightMissing(missing) {
                if (missing.length === 0) return false;
                var first = missing[0];
                // Try row highlight (likert table) or scale-row highlight
                var row = first.closest('tr') || first.closest('.hl-tsa-scale-row');
                if (row) {
                    row.style.outline = '2px solid #EF4444';
                    row.style.outlineOffset = '-2px';
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(function() {
                        row.style.outline = '';
                        row.style.outlineOffset = '';
                    }, 3000);
                }
                alert('<?php echo esc_js( __( 'Please answer all questions in this section before continuing.', 'hl-core' ) ); ?>');
                return true;
            }

            /**
             * Show the skip-confirmation modal for unanswered optional sections.
             * "Submit" calls onContinue. "Return" navigates to returnToStep (first missing section).
             */
            function showSkipModal(onContinue, returnToStep) {
                var overlay = document.getElementById('hl-tsa-skip-overlay-<?php echo $esc_id; ?>');
                if (!overlay) { if (onContinue) onContinue(); return; }
                overlay.style.display = 'flex';

                var returnBtn  = overlay.querySelector('.hl-tsa-skip-return');
                var submitBtn2 = overlay.querySelector('.hl-tsa-skip-submit');

                // Clone-replace to remove stale listeners from prior invocations
                var newReturn = returnBtn.cloneNode(true);
                returnBtn.parentNode.replaceChild(newReturn, returnBtn);
                var newSubmit = submitBtn2.cloneNode(true);
                submitBtn2.parentNode.replaceChild(newSubmit, submitBtn2);

                newReturn.addEventListener('click', function() {
                    overlay.style.display = 'none';
                    if (typeof returnToStep === 'number' && typeof goToStep === 'function') {
                        goToStep(returnToStep);
                    }
                });
                newSubmit.addEventListener('click', function() {
                    overlay.style.display = 'none';
                    if (onContinue) onContinue();
                });
            }

            // Track which optional sections the user already skip-confirmed during navigation
            var skipConfirmedSteps = {};
            // Track whether optional-section skip was already confirmed for this submit attempt
            var optionalSkipConfirmed = false;

            // All draft buttons (main + nav-inline) — disable validation
            form.querySelectorAll('.hl-btn-save-draft').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    hiddenField.value = '0';
                });
            });

            if (submitBtn) submitBtn.addEventListener('click', function(e) {
                e.preventDefault();
                hiddenField.value = '1';

                // 1. Section 1 (step 0) is mandatory — block if incomplete
                var section1 = form.querySelector('.hl-tsa-section[data-step="0"]');
                if (section1) {
                    var missingS1 = findMissingInContainer(section1);
                    if (missingS1.length > 0) {
                        if (typeof goToStep === 'function') goToStep(0);
                        highlightMissing(missingS1);
                        return;
                    }
                }

                // 2. Sections 2+ are optional — show skip confirmation if any unanswered
                if (!optionalSkipConfirmed) {
                    var hasUnconfirmedMissing = false;
                    var firstMissingStep = -1;
                    var allSections = form.querySelectorAll('.hl-tsa-section[data-step]');
                    for (var si = 0; si < allSections.length; si++) {
                        var stepVal = parseInt(allSections[si].getAttribute('data-step'), 10);
                        if (stepVal > 0 && !skipConfirmedSteps[stepVal] && findMissingInContainer(allSections[si]).length > 0) {
                            hasUnconfirmedMissing = true;
                            if (firstMissingStep < 0) firstMissingStep = stepVal;
                        }
                    }

                    if (hasUnconfirmedMissing) {
                        showSkipModal(function() {
                            optionalSkipConfirmed = true;
                            submitBtn.click();
                        }, firstMissingStep);
                        return;
                    }
                }
                optionalSkipConfirmed = false;

                // 3. Final confirmation
                if (!confirm('<?php echo esc_js( __( 'Once submitted, answers cannot be changed. Continue?', 'hl-core' ) ); ?>')) {
                    return;
                }

                // 4. Submit the form (inject hidden action field since form.submit() skips button values)
                var actionInput = form.querySelector('input[name="hl_tsa_action"]');
                if (!actionInput) {
                    actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'hl_tsa_action';
                    form.appendChild(actionInput);
                }
                actionInput.value = 'submit';
                form.submit();
            });

            // ── Pagination ──────────────────────────────────────
            var isPaginated = form.classList.contains('hl-tsa-form--paginated');
            var pSections, pSteps, pActions, pStepLabel, totalSteps, currentStep;

            function goToStep(step) {
                if (!isPaginated || !pSections || step < 0 || step >= totalSteps) return;

                pSections.forEach(function(s) { s.classList.remove('hl-tsa-section--active'); });
                pSections[step].classList.add('hl-tsa-section--active');

                pSteps.forEach(function(s, i) {
                    s.classList.remove('hl-tsa-step--active', 'hl-tsa-step--completed');
                    if (i < step) s.classList.add('hl-tsa-step--completed');
                    if (i === step) s.classList.add('hl-tsa-step--active');
                });

                if (pStepLabel) pStepLabel.textContent = step + 1;

                if (pActions) {
                    if (step === totalSteps - 1) {
                        pActions.classList.add('hl-tsa-actions--visible');
                    } else {
                        pActions.classList.remove('hl-tsa-actions--visible');
                    }
                }

                currentStep = step;
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            if (isPaginated) {
                pSections   = form.querySelectorAll('.hl-tsa-section[data-step]');
                pSteps      = form.querySelectorAll('.hl-tsa-step');
                pActions    = form.querySelector('.hl-tsa-actions');
                pStepLabel  = form.querySelector('.hl-tsa-step-current');
                totalSteps  = pSections.length;
                currentStep = 0;

                if (totalSteps > 1) {
                    /**
                     * Validate the current section before allowing forward navigation.
                     * Section 0: mandatory — blocks with alert.
                     * Sections 1+: optional — shows skip confirmation modal.
                     */
                    function validateCurrentSection(onValid) {
                        if (!pSections || !pSections[currentStep]) { onValid(); return; }
                        var missing = findMissingInContainer(pSections[currentStep]);
                        if (missing.length === 0) { onValid(); return; }

                        if (currentStep === 0) {
                            // Section 1: mandatory — block navigation
                            highlightMissing(missing);
                            return;
                        }

                        // Sections 2+: show skip confirmation
                        var stepToConfirm = currentStep;
                        showSkipModal(function() {
                            skipConfirmedSteps[stepToConfirm] = true;
                            onValid();
                        });
                    }

                    form.addEventListener('click', function(e) {
                        var target = e.target;
                        if (target.classList.contains('hl-tsa-btn-next')) {
                            e.preventDefault();
                            validateCurrentSection(function() {
                                goToStep(currentStep + 1);
                            });
                        } else if (target.classList.contains('hl-tsa-btn-prev')) {
                            e.preventDefault();
                            goToStep(currentStep - 1);
                        }
                    });

                    pSteps.forEach(function(stepEl) {
                        stepEl.addEventListener('click', function() {
                            var targetStep = parseInt(stepEl.getAttribute('data-step'), 10);
                            // Only validate when moving forward
                            if (targetStep > currentStep) {
                                validateCurrentSection(function() {
                                    goToStep(targetStep);
                                });
                            } else {
                                goToStep(targetStep);
                            }
                        });
                    });

                    goToStep(0);
                }
            }
        })();
        </script>
        <?php
    }
}
