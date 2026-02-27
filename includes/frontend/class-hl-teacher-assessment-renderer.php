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
            if ( $type === 'scale' ) {
                $this->render_scale_section( $section_key, $items, $labels, $retrospective );
            } else {
                $this->render_likert_section( $section_key, $items, $labels, $retrospective );
            }
            ?>

            <?php if ( $paginate && ! $this->read_only ) : ?>
            <div class="hl-tsa-nav">
                <?php if ( ! $is_first ) : ?>
                    <button type="button" class="button hl-tsa-btn-prev"><?php esc_html_e( 'Previous Section', 'hl-core' ); ?></button>
                <?php endif; ?>
                <button type="submit" name="hl_tsa_action" value="draft" class="button hl-btn-save-draft hl-tsa-nav-draft"><?php esc_html_e( 'Save Draft', 'hl-core' ); ?></button>
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
    private function render_likert_section( $section_key, $items, $labels, $retrospective = false ) {
        // Labels is an indexed array like ["Strongly Disagree", ..., "Strongly Agree"]
        $num_options = count( $labels );
        if ( $num_options < 2 ) {
            $num_options = 5;
            $labels = array( '1', '2', '3', '4', '5' );
        }

        $is_post = $retrospective;

        ?>
        <div class="hl-tsa-table-wrap">
            <table class="hl-tsa-likert-table">
                <thead>
                    <tr>
                        <th class="hl-tsa-item-col"><?php esc_html_e( 'Statement', 'hl-core' ); ?></th>
                        <?php if ( $is_post ) : ?>
                            <th class="hl-tsa-group-header" colspan="<?php echo esc_attr( $num_options ); ?>">
                                <?php esc_html_e( 'Prior Assessment Cycle', 'hl-core' ); ?>
                            </th>
                            <th class="hl-tsa-group-header hl-tsa-now-header" colspan="<?php echo esc_attr( $num_options ); ?>">
                                <?php esc_html_e( 'Past Two Weeks', 'hl-core' ); ?>
                            </th>
                        <?php else : ?>
                            <?php foreach ( $labels as $label ) : ?>
                                <th class="hl-tsa-label-col"><?php echo esc_html( $label ); ?></th>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                    <?php if ( $is_post ) : ?>
                    <tr class="hl-tsa-sublabel-row">
                        <th></th>
                        <?php foreach ( $labels as $label ) : ?>
                            <th class="hl-tsa-label-col hl-tsa-before-label"><?php echo esc_html( $label ); ?></th>
                        <?php endforeach; ?>
                        <?php foreach ( $labels as $label ) : ?>
                            <th class="hl-tsa-label-col hl-tsa-now-label"><?php echo esc_html( $label ); ?></th>
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

    // ─── Inline styles ──────────────────────────────────────────────────

    private function render_inline_styles() {
        ?>
        <style>
            /* ── Card container ──────────────────────────────── */
            .hl-tsa-form-wrap {
                max-width: 900px;
                margin: 2em auto;
                background: var(--hl-surface, #ffffff);
                border-radius: var(--hl-radius, 12px);
                box-shadow: var(--hl-shadow, 0 1px 3px rgba(0,0,0,0.08), 0 4px 16px rgba(0,0,0,0.04));
                padding: 32px 40px;
            }

            /* ── Header ─────────────────────────────────────── */
            .hl-tsa-header {
                text-align: center;
                margin-bottom: 1.5em;
                padding-bottom: 1.5em;
                border-bottom: 1px solid var(--hl-border, #E5E7EB);
            }
            .hl-tsa-header h2 {
                margin: 0 0 6px 0;
                font-size: 1.6em;
                font-weight: 700;
                color: var(--hl-text, #1F2937);
            }
            .hl-tsa-program-name {
                display: block;
                font-size: 0.95em;
                color: var(--hl-text-secondary, #6B7280);
                margin-bottom: 8px;
            }
            .hl-tsa-phase-badge {
                display: inline-block;
                padding: 3px 12px;
                border-radius: 20px;
                font-size: 0.75em;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }
            .hl-tsa-phase-pre {
                background: #e3f2fd;
                color: #1565c0;
            }
            .hl-tsa-phase-post {
                background: #e8f5e9;
                color: #2e7d32;
            }

            /* ── Instructions ────────────────────────────────── */
            .hl-tsa-instructions {
                background: var(--hl-surface-alt, #F9FAFB);
                border: 1px solid var(--hl-border, #E5E7EB);
                border-radius: var(--hl-radius, 12px);
                padding: 16px 20px;
                margin-bottom: 1.5em;
                font-size: 0.95em;
                line-height: 1.6;
                color: var(--hl-text-secondary, #4B5563);
            }
            .hl-tsa-instructions p:first-child { margin-top: 0; }
            .hl-tsa-instructions p:last-child { margin-bottom: 0; }

            .hl-tsa-notice {
                padding: 14px 18px;
                background: #fff8e1;
                border-left: 4px solid #ffb300;
                border-radius: 0 8px 8px 0;
                margin: 1em 0;
            }

            /* ── Step indicator (modern dots) ────────────────── */
            .hl-tsa-step-indicator {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
                margin-bottom: 2em;
                padding: 16px 0 8px;
            }
            .hl-tsa-step-label {
                font-size: 0.85em;
                color: var(--hl-text-secondary, #6B7280);
                font-weight: 500;
            }
            .hl-tsa-step-dots {
                display: flex;
                gap: 10px;
            }
            .hl-tsa-step {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                border: 2px solid var(--hl-border, #D1D5DB);
                font-size: 0.85em;
                font-weight: 600;
                color: var(--hl-text-secondary, #9CA3AF);
                background: #fff;
                cursor: pointer;
                transition: all 0.25s ease;
            }
            .hl-tsa-step:hover {
                border-color: var(--hl-primary, #2271b1);
                color: var(--hl-primary, #2271b1);
            }
            .hl-tsa-step--active {
                border-color: var(--hl-primary, #2271b1);
                background: var(--hl-primary, #2271b1);
                color: #fff;
                transform: scale(1.1);
            }
            .hl-tsa-step--completed {
                border-color: #2e7d32;
                background: #e8f5e9;
                color: #2e7d32;
            }

            /* ── Section ─────────────────────────────────────── */
            .hl-tsa-section {
                margin-bottom: 2.5em;
            }
            .hl-tsa-section-title {
                font-size: 1.25em;
                font-weight: 600;
                margin: 0 0 0.4em 0;
                padding-bottom: 0.4em;
                border-bottom: 2px solid var(--hl-border, #E5E7EB);
                color: var(--hl-text, #1F2937);
            }
            .hl-tsa-section-desc {
                color: var(--hl-text-secondary, #6B7280);
                font-size: 0.93em;
                margin: 0 0 1.2em 0;
                line-height: 1.5;
            }

            /* ── Likert table ────────────────────────────────── */
            .hl-tsa-table-wrap {
                overflow-x: auto;
                margin-bottom: 1em;
                -webkit-overflow-scrolling: touch;
                border-radius: 8px;
                border: 1px solid var(--hl-border, #E5E7EB);
            }
            table.hl-tsa-likert-table {
                border-collapse: collapse;
                width: 100%;
                font-size: 0.93em;
            }
            table.hl-tsa-likert-table th,
            table.hl-tsa-likert-table td {
                border: 1px solid var(--hl-border, #E5E7EB);
                padding: 10px 8px;
                text-align: center;
                vertical-align: middle;
            }
            table.hl-tsa-likert-table .hl-tsa-item-col,
            table.hl-tsa-likert-table .hl-tsa-item-cell {
                text-align: left;
                min-width: 280px;
                max-width: 420px;
                line-height: 1.4;
            }
            table.hl-tsa-likert-table thead th {
                background: var(--hl-surface-alt, #F9FAFB);
                font-weight: 600;
                font-size: 0.88em;
            }
            table.hl-tsa-likert-table .hl-tsa-label-col {
                min-width: 50px;
                max-width: 100px;
                font-size: 0.92em;
                line-height: 1.3;
            }
            .hl-tsa-group-header {
                background: #e8e8e8 !important;
                font-weight: 700 !important;
                font-size: 0.9em !important;
            }
            .hl-tsa-now-header {
                background: #e8f5e9 !important;
            }
            .hl-tsa-sublabel-row th {
                font-size: 0.8em !important;
                padding: 5px 4px !important;
            }
            .hl-tsa-before-label {
                background: var(--hl-surface-alt, #F9FAFB);
            }
            .hl-tsa-now-label {
                background: #f0faf0;
            }
            tr.hl-tsa-row-even td { background-color: #ffffff; }
            tr.hl-tsa-row-odd td  { background-color: var(--hl-surface-alt, #FAFAFA); }
            .hl-tsa-radio-cell {
                width: 44px;
                min-width: 44px;
            }

            /* Custom radio styling */
            .hl-tsa-form-wrap input[type="radio"].hl-tsa-radio {
                appearance: none !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                width: 22px !important;
                height: 22px !important;
                min-width: 22px !important;
                min-height: 22px !important;
                border: 2px solid #D1D5DB !important;
                border-radius: 50% !important;
                cursor: pointer;
                transition: all 0.15s ease;
                margin: 0 !important;
                padding: 0 !important;
                position: relative;
                background: #fff !important;
                box-shadow: none !important;
                outline: none !important;
                flex-shrink: 0;
            }
            .hl-tsa-form-wrap input[type="radio"].hl-tsa-radio:hover {
                border-color: var(--hl-primary, #2271b1) !important;
            }
            .hl-tsa-form-wrap input[type="radio"].hl-tsa-radio:checked {
                border-color: var(--hl-primary, #2271b1) !important;
                background: var(--hl-primary, #2271b1) !important;
            }
            .hl-tsa-form-wrap input[type="radio"].hl-tsa-radio:checked::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #fff;
            }
            /* Scale-section radios: slightly larger for touchability */
            .hl-tsa-scale-label input[type="radio"].hl-tsa-radio {
                width: 24px !important;
                height: 24px !important;
                min-width: 24px !important;
                min-height: 24px !important;
            }

            .hl-tsa-before-col {
                background-color: var(--hl-surface-alt, #F9F9F9) !important;
            }
            .hl-tsa-now-col {
                background-color: #f5fdf5 !important;
            }
            .hl-tsa-radio-disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* ── Scale section ───────────────────────────────── */
            .hl-tsa-scale-section {
                margin-bottom: 1em;
            }
            .hl-tsa-scale-anchors {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                font-size: 0.85em;
                color: var(--hl-text-secondary, #6B7280);
                font-style: italic;
                margin-bottom: 0.5em;
            }
            .hl-tsa-scale-row {
                padding: 16px 18px;
                border: 1px solid var(--hl-border, #E5E7EB);
                border-radius: 8px;
                margin-bottom: 10px;
                background: #fff;
            }
            .hl-tsa-scale-row.hl-tsa-row-odd {
                background: var(--hl-surface-alt, #FAFAFA);
            }
            .hl-tsa-scale-text {
                margin-bottom: 10px;
                font-size: 0.95em;
                line-height: 1.4;
            }
            .hl-tsa-scale-radios {
                display: flex;
                gap: 4px;
                flex-wrap: wrap;
                align-items: center;
            }
            .hl-tsa-scale-label {
                display: flex;
                flex-direction: column;
                align-items: center;
                min-width: 36px;
                cursor: pointer;
                padding: 4px 6px;
                font-size: 0.95em;
                text-align: center;
                border-radius: 6px;
                transition: background 0.15s ease;
            }
            .hl-tsa-scale-label:hover {
                background: var(--hl-surface-alt, #F3F4F6);
            }
            .hl-tsa-scale-label span {
                margin-top: 3px;
                color: var(--hl-text-secondary, #6B7280);
                font-size: 0.9em;
            }
            .hl-tsa-scale-dual {
                display: flex;
                gap: 24px;
                flex-wrap: wrap;
            }
            .hl-tsa-scale-group {
                flex: 1;
                min-width: 280px;
            }
            .hl-tsa-group-label {
                display: block;
                font-size: 0.8em;
                font-weight: 600;
                color: var(--hl-text-secondary, #6B7280);
                margin-bottom: 6px;
                text-transform: uppercase;
                letter-spacing: 0.03em;
            }
            .hl-tsa-before-group {
                opacity: 0.6;
            }

            /* ── Action buttons ──────────────────────────────── */
            .hl-tsa-actions {
                display: flex;
                gap: 14px;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                padding: 2em 0 1em;
                border-top: 1px solid var(--hl-border, #E5E7EB);
                margin-top: 1em;
            }
            .hl-tsa-actions .button {
                padding: 10px 28px;
                font-size: 0.95em;
                border-radius: 8px;
                min-height: 44px;
            }
            .hl-tsa-form-wrap .hl-tsa-actions .hl-btn-submit-assessment {
                background: var(--hl-primary, #2271b1) !important;
                border-color: var(--hl-primary, #2271b1) !important;
                color: #fff !important;
            }
            .hl-tsa-form-wrap .hl-tsa-actions .hl-btn-submit-assessment:hover {
                background: var(--hl-primary-dark, #135e96) !important;
            }
            .hl-tsa-form-wrap .hl-tsa-actions .hl-btn-save-draft {
                background: #fff !important;
                border-color: var(--hl-border, #D1D5DB) !important;
                color: var(--hl-text, #374151) !important;
            }
            .hl-tsa-form-wrap .hl-tsa-actions .hl-btn-save-draft:hover {
                background: var(--hl-surface-alt, #F3F4F6) !important;
            }

            /* ── Pagination ──────────────────────────────────── */
            .hl-tsa-form--paginated .hl-tsa-section {
                display: none;
            }
            .hl-tsa-form--paginated .hl-tsa-section--active {
                display: block;
            }
            .hl-tsa-form--paginated .hl-tsa-actions {
                display: none;
            }
            .hl-tsa-form--paginated .hl-tsa-actions--visible {
                display: flex;
            }

            /* ── Section navigation buttons ──────────────────── */
            .hl-tsa-nav {
                display: flex;
                gap: 14px;
                padding: 1.5em 0 0.5em;
                border-top: 1px solid var(--hl-border, #E5E7EB);
                margin-top: 1.5em;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .button {
                padding: 10px 24px;
                border-radius: 8px;
                min-height: 44px;
                font-size: 0.95em;
                font-weight: 500;
                transition: all 0.15s ease;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-btn-prev {
                background: #fff !important;
                border-color: var(--hl-border, #D1D5DB) !important;
                color: var(--hl-text, #374151) !important;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-btn-prev:hover {
                background: var(--hl-surface-alt, #F3F4F6) !important;
                border-color: #9CA3AF !important;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-btn-prev::before {
                content: '\2190\00a0';
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-nav-draft {
                background: #fff !important;
                border-color: var(--hl-border, #D1D5DB) !important;
                color: var(--hl-text-muted, #6B7280) !important;
                font-size: 0.85em;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-nav-draft:hover {
                background: var(--hl-surface-alt, #F3F4F6) !important;
                border-color: #9CA3AF !important;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-btn-next {
                margin-left: auto;
                background: var(--hl-primary, #2271b1) !important;
                border-color: var(--hl-primary, #2271b1) !important;
                color: #fff !important;
            }
            .hl-tsa-form-wrap .hl-tsa-nav .hl-tsa-btn-next:hover {
                background: var(--hl-primary-dark, #135e96) !important;
                border-color: var(--hl-primary-dark, #135e96) !important;
            }
            .hl-tsa-form-wrap .hl-tsa-btn-next::after {
                content: '\00a0\2192';
            }

            /* ── Responsive ──────────────────────────────────── */
            @media screen and (max-width: 768px) {
                .hl-tsa-form-wrap {
                    padding: 20px 16px;
                    margin: 1em 0;
                    border-radius: 8px;
                }
                .hl-tsa-header h2 {
                    font-size: 1.3em;
                }
                table.hl-tsa-likert-table .hl-tsa-item-col,
                table.hl-tsa-likert-table .hl-tsa-item-cell {
                    min-width: 200px;
                }
                .hl-tsa-scale-row {
                    padding: 12px;
                }
                .hl-tsa-actions .button {
                    width: 100%;
                    text-align: center;
                }
            }

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

        $map = array(
            'instructions_font_size'  => array( '.hl-tsa-instructions', 'font-size' ),
            'instructions_color'      => array( '.hl-tsa-instructions', 'color' ),
            'section_title_font_size' => array( '.hl-tsa-section-title', 'font-size' ),
            'section_title_color'     => array( '.hl-tsa-section-title', 'color' ),
            'section_desc_font_size'  => array( '.hl-tsa-section-desc', 'font-size' ),
            'section_desc_color'      => array( '.hl-tsa-section-desc', 'color' ),
            'item_font_size'          => array( 'table.hl-tsa-likert-table .hl-tsa-item-cell, .hl-tsa-scale-text', 'font-size' ),
            'item_color'              => array( 'table.hl-tsa-likert-table .hl-tsa-item-cell, .hl-tsa-scale-text', 'color' ),
            'scale_label_font_size'   => array( 'table.hl-tsa-likert-table thead th, table.hl-tsa-likert-table .hl-tsa-label-col', 'font-size' ),
            'scale_label_color'       => array( 'table.hl-tsa-likert-table thead th, table.hl-tsa-likert-table .hl-tsa-label-col', 'color' ),
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

            // All draft buttons (main + nav-inline) — disable validation
            form.querySelectorAll('.hl-btn-save-draft').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    hiddenField.value = '0';
                });
            });

            if (submitBtn) submitBtn.addEventListener('click', function(e) {
                if (!confirm('<?php echo esc_js( __( 'Once submitted, answers cannot be changed. Continue?', 'hl-core' ) ); ?>')) {
                    e.preventDefault();
                    return;
                }

                hiddenField.value = '1';

                // Custom validation — check all required groups including hidden sections
                var missing = findMissingRequiredGroups();
                if (missing.length > 0) {
                    e.preventDefault();

                    // If paginated, navigate to the section containing the first missing answer
                    var firstMissing = missing[0];
                    var section = firstMissing.closest('.hl-tsa-section[data-step]');
                    if (section && typeof goToStep === 'function') {
                        var step = parseInt(section.getAttribute('data-step'), 10);
                        goToStep(step);
                    }

                    highlightMissing(missing);
                    return;
                }

                // All answered — submit the form
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
                     * Returns false if validation fails (missing answers).
                     */
                    function validateCurrentSection() {
                        if (!pSections || !pSections[currentStep]) return true;
                        var missing = findMissingInContainer(pSections[currentStep]);
                        return !highlightMissing(missing);
                    }

                    form.addEventListener('click', function(e) {
                        var target = e.target;
                        if (target.classList.contains('hl-tsa-btn-next')) {
                            e.preventDefault();
                            if (validateCurrentSection()) {
                                goToStep(currentStep + 1);
                            }
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
                                if (!validateCurrentSection()) return;
                            }
                            goToStep(targetStep);
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
