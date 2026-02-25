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

    /**
     * Constructor.
     *
     * @param HL_Teacher_Assessment_Instrument $instrument         Instrument definition.
     * @param object                           $instance           Instance row.
     * @param string                           $phase              'pre' or 'post'.
     * @param array                            $existing_responses Decoded responses_json for this instance.
     * @param array                            $pre_responses      Decoded PRE responses (only used for POST phase).
     * @param bool                             $read_only          Whether the form is read-only.
     */
    public function __construct( $instrument, $instance, $phase, $existing_responses = array(), $pre_responses = array(), $read_only = false ) {
        $this->instrument         = $instrument;
        $this->instance           = (object) $instance;
        $this->phase              = $phase;
        $this->existing_responses = is_array( $existing_responses ) ? $existing_responses : array();
        $this->pre_responses      = is_array( $pre_responses ) ? $pre_responses : array();
        $this->read_only          = $read_only;
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
                <span class="hl-tsa-phase-badge hl-tsa-phase-<?php echo esc_attr( $this->phase ); ?>">
                    <?php echo esc_html( $phase_label ); ?>
                </span>
                <?php if ( ! empty( $this->instrument->instrument_version ) ) : ?>
                    <span class="hl-tsa-version">
                        <?php printf( esc_html__( 'v%s', 'hl-core' ), esc_html( $this->instrument->instrument_version ) ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( $this->phase === 'post' ) : ?>
                <div class="hl-tsa-post-instructions">
                    <p><?php esc_html_e( 'For each item, the "Before Program" column shows your pre-program rating. Please rate how you feel now in the "Now" column.', 'hl-core' ); ?></p>
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
                <p class="hl-tsa-section-desc"><?php echo esc_html( $description ); ?></p>
            <?php endif; ?>

            <?php
            if ( $type === 'scale' ) {
                $this->render_scale_section( $section_key, $items, $labels );
            } else {
                $this->render_likert_section( $section_key, $items, $labels );
            }
            ?>

            <?php if ( $paginate ) : ?>
            <div class="hl-tsa-nav">
                <?php if ( ! $is_first ) : ?>
                    <button type="button" class="button hl-tsa-btn-prev"><?php esc_html_e( 'Previous Section', 'hl-core' ); ?></button>
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
    private function render_likert_section( $section_key, $items, $labels ) {
        // Labels is an indexed array like ["Strongly Disagree", ..., "Strongly Agree"]
        $num_options = count( $labels );
        if ( $num_options < 2 ) {
            $num_options = 5;
            $labels = array( '1', '2', '3', '4', '5' );
        }

        $is_post = ( $this->phase === 'post' );

        ?>
        <div class="hl-tsa-table-wrap">
            <table class="hl-tsa-likert-table">
                <thead>
                    <tr>
                        <th class="hl-tsa-item-col"><?php esc_html_e( 'Statement', 'hl-core' ); ?></th>
                        <?php if ( $is_post ) : ?>
                            <th class="hl-tsa-group-header" colspan="<?php echo esc_attr( $num_options ); ?>">
                                <?php esc_html_e( 'Before Program', 'hl-core' ); ?>
                            </th>
                            <th class="hl-tsa-group-header hl-tsa-now-header" colspan="<?php echo esc_attr( $num_options ); ?>">
                                <?php esc_html_e( 'Now', 'hl-core' ); ?>
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
                        <td class="hl-tsa-item-cell"><?php echo esc_html( $item_text ); ?></td>
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
    private function render_scale_section( $section_key, $items, $labels ) {
        $global_low  = isset( $labels['low'] ) ? $labels['low'] : '0';
        $global_high = isset( $labels['high'] ) ? $labels['high'] : '10';
        $is_post     = ( $this->phase === 'post' );

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
                <div class="hl-tsa-scale-text"><?php echo esc_html( $item_text ); ?></div>
                <div class="hl-tsa-scale-anchors">
                    <span class="hl-tsa-anchor-low">0 = <?php echo esc_html( $low_label ); ?></span>
                    <span class="hl-tsa-anchor-high">10 = <?php echo esc_html( $high_label ); ?></span>
                </div>

                <?php if ( $is_post ) : ?>
                    <div class="hl-tsa-scale-dual">
                        <div class="hl-tsa-scale-group hl-tsa-before-group">
                            <span class="hl-tsa-group-label"><?php esc_html_e( 'Before Program', 'hl-core' ); ?></span>
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
                            <span class="hl-tsa-group-label"><?php esc_html_e( 'Now', 'hl-core' ); ?></span>
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
            .hl-tsa-form-wrap {
                max-width: 100%;
                margin: 1.5em 0;
            }
            .hl-tsa-header {
                margin-bottom: 1em;
                display: flex;
                align-items: baseline;
                gap: 12px;
                flex-wrap: wrap;
            }
            .hl-tsa-header h2 {
                margin: 0;
                font-size: 1.4em;
            }
            .hl-tsa-phase-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 12px;
                font-size: 0.8em;
                font-weight: 600;
                text-transform: uppercase;
            }
            .hl-tsa-phase-pre {
                background: #e3f2fd;
                color: #1565c0;
            }
            .hl-tsa-phase-post {
                background: #e8f5e9;
                color: #2e7d32;
            }
            .hl-tsa-version {
                color: #666;
                font-size: 0.85em;
            }
            .hl-tsa-post-instructions {
                background: #f0f6ff;
                border-left: 4px solid #2271b1;
                padding: 10px 16px;
                margin-bottom: 1.5em;
                font-size: 0.95em;
            }
            .hl-tsa-post-instructions p {
                margin: 0;
            }
            .hl-tsa-notice {
                padding: 12px 16px;
                background: #fff8e1;
                border-left: 4px solid #ffb300;
                margin: 1em 0;
            }

            /* Section */
            .hl-tsa-section {
                margin-bottom: 2em;
            }
            .hl-tsa-section-title {
                font-size: 1.15em;
                margin: 0 0 0.3em 0;
                padding-bottom: 0.3em;
                border-bottom: 2px solid #e0e0e0;
            }
            .hl-tsa-section-desc {
                color: #555;
                font-size: 0.93em;
                margin: 0 0 1em 0;
            }

            /* Likert table */
            .hl-tsa-table-wrap {
                overflow-x: auto;
                margin-bottom: 1em;
                -webkit-overflow-scrolling: touch;
            }
            table.hl-tsa-likert-table {
                border-collapse: collapse;
                width: 100%;
                font-size: 0.9em;
            }
            table.hl-tsa-likert-table th,
            table.hl-tsa-likert-table td {
                border: 1px solid #ddd;
                padding: 8px 6px;
                text-align: center;
                vertical-align: middle;
            }
            table.hl-tsa-likert-table .hl-tsa-item-col,
            table.hl-tsa-likert-table .hl-tsa-item-cell {
                text-align: left;
                min-width: 280px;
                max-width: 420px;
            }
            table.hl-tsa-likert-table thead th {
                background: #f5f5f5;
                font-weight: 600;
                font-size: 0.85em;
            }
            table.hl-tsa-likert-table .hl-tsa-label-col {
                min-width: 50px;
                max-width: 100px;
                font-size: 0.78em;
                line-height: 1.2;
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
                font-size: 0.75em !important;
                padding: 4px 3px !important;
            }
            .hl-tsa-before-label {
                background: #f5f5f5;
            }
            .hl-tsa-now-label {
                background: #f0faf0;
            }
            tr.hl-tsa-row-even td { background-color: #ffffff; }
            tr.hl-tsa-row-odd td  { background-color: #fafafa; }
            .hl-tsa-radio-cell {
                width: 40px;
                min-width: 40px;
            }
            .hl-tsa-before-col {
                background-color: #f9f9f9 !important;
            }
            .hl-tsa-now-col {
                background-color: #f5fdf5 !important;
            }
            .hl-tsa-radio-disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* Scale section */
            .hl-tsa-scale-section {
                margin-bottom: 1em;
            }
            .hl-tsa-scale-anchors {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                font-size: 0.85em;
                color: #666;
                font-style: italic;
                margin-bottom: 0.5em;
            }
            .hl-tsa-scale-row {
                padding: 12px 14px;
                border: 1px solid #eee;
                margin-bottom: -1px;
            }
            .hl-tsa-scale-row.hl-tsa-row-odd {
                background: #fafafa;
            }
            .hl-tsa-scale-text {
                margin-bottom: 8px;
                font-size: 0.93em;
            }
            .hl-tsa-scale-radios {
                display: flex;
                gap: 2px;
                flex-wrap: wrap;
                align-items: center;
            }
            .hl-tsa-scale-label {
                display: flex;
                flex-direction: column;
                align-items: center;
                min-width: 32px;
                cursor: pointer;
                padding: 2px 4px;
                font-size: 0.82em;
                text-align: center;
            }
            .hl-tsa-scale-label span {
                margin-top: 2px;
                color: #666;
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
                color: #555;
                margin-bottom: 4px;
                text-transform: uppercase;
            }
            .hl-tsa-before-group {
                opacity: 0.65;
            }

            /* Actions */
            .hl-tsa-actions {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
                padding: 1.5em 0 1em;
            }

            /* Pagination — sections hidden by default when paginated */
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

            /* Step indicator */
            .hl-tsa-step-indicator {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                margin-bottom: 1.5em;
                padding: 12px 0;
            }
            .hl-tsa-step-label {
                font-size: 0.9em;
                color: #555;
                font-weight: 500;
            }
            .hl-tsa-step-dots {
                display: flex;
                gap: 8px;
            }
            .hl-tsa-step {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                border: 2px solid #ccc;
                font-size: 0.85em;
                font-weight: 600;
                color: #888;
                background: #fff;
                cursor: pointer;
                transition: all 0.2s;
            }
            .hl-tsa-step:hover {
                border-color: #999;
            }
            .hl-tsa-step--active {
                border-color: #2271b1;
                background: #2271b1;
                color: #fff;
            }
            .hl-tsa-step--completed {
                border-color: #2e7d32;
                background: #e8f5e9;
                color: #2e7d32;
            }

            /* Section navigation buttons */
            .hl-tsa-nav {
                display: flex;
                gap: 12px;
                padding: 1.5em 0 0.5em;
                border-top: 1px solid #eee;
                margin-top: 1.5em;
            }
            .hl-tsa-btn-next {
                margin-left: auto;
            }
        </style>
        <?php
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
            var draftBtnId    = 'hl-tsa-btn-draft-<?php echo $esc_id; ?>';
            var submitBtnId   = 'hl-tsa-btn-submit-<?php echo $esc_id; ?>';

            var form        = document.getElementById(formId);
            var hiddenField = document.getElementById(hiddenFieldId);
            var draftBtn    = document.getElementById(draftBtnId);
            var submitBtn   = document.getElementById(submitBtnId);

            if (!form || !hiddenField || !draftBtn || !submitBtn) return;

            function setValidation(enable) {
                hiddenField.value = enable ? '1' : '0';
                var radios = form.querySelectorAll('input[type="radio"][data-hl-required="1"]');

                // Group radios by name; set required on each group
                var groups = {};
                radios.forEach(function(r) {
                    if (!groups[r.name]) groups[r.name] = [];
                    groups[r.name].push(r);
                });

                Object.keys(groups).forEach(function(name) {
                    groups[name].forEach(function(r) {
                        if (enable) {
                            r.setAttribute('required', 'required');
                        } else {
                            r.removeAttribute('required');
                        }
                    });
                });
            }

            draftBtn.addEventListener('click', function() {
                setValidation(false);
            });

            submitBtn.addEventListener('click', function(e) {
                if (!confirm('<?php echo esc_js( __( 'Once submitted, answers cannot be changed. Continue?', 'hl-core' ) ); ?>')) {
                    e.preventDefault();
                    return;
                }
                setValidation(true);
            });

            // ── Pagination ──────────────────────────────────────
            if (!form.classList.contains('hl-tsa-form--paginated')) return;

            var pSections   = form.querySelectorAll('.hl-tsa-section[data-step]');
            var pSteps      = form.querySelectorAll('.hl-tsa-step');
            var pActions    = form.querySelector('.hl-tsa-actions');
            var pStepLabel  = form.querySelector('.hl-tsa-step-current');
            var totalSteps  = pSections.length;
            var currentStep = 0;

            if (totalSteps <= 1) return;

            function goToStep(step) {
                if (step < 0 || step >= totalSteps) return;

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

            form.addEventListener('click', function(e) {
                var target = e.target;
                if (target.classList.contains('hl-tsa-btn-next')) {
                    e.preventDefault();
                    goToStep(currentStep + 1);
                } else if (target.classList.contains('hl-tsa-btn-prev')) {
                    e.preventDefault();
                    goToStep(currentStep - 1);
                }
            });

            pSteps.forEach(function(stepEl) {
                stepEl.addEventListener('click', function() {
                    goToStep(parseInt(stepEl.getAttribute('data-step'), 10));
                });
            });

            goToStep(0);
        })();
        </script>
        <?php
    }
}
