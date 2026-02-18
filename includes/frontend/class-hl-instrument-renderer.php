<?php
if (!defined('ABSPATH')) exit;

/**
 * Renders a children assessment instrument as an HTML matrix/table form.
 *
 * One row per child in the classroom roster, one column per question
 * from the instrument definition. Supports save-as-draft and final submit.
 *
 * This is NOT a singleton -- instantiated with data each time.
 *
 * @package HL_Core
 * @since   1.0.0
 */
class HL_Instrument_Renderer {

    /** @var object|array Instrument data with instrument_id, name, questions. */
    private $instrument;

    /** @var array Parsed questions array from instrument JSON. */
    private $questions;

    /** @var array Array of child objects (child_id, first_name, last_name, child_display_code, dob). */
    private $children;

    /** @var int Children assessment instance ID. */
    private $instance_id;

    /** @var array Existing answers keyed by child_id, each containing decoded answers_json. */
    private $existing_answers;

    /**
     * Constructor.
     *
     * @param object|array $instrument      Instrument with instrument_id, name, questions (JSON string or array).
     * @param array        $children        Array of child objects.
     * @param int          $instance_id     The children_assessment_instance ID.
     * @param array        $existing_answers Optional. Existing answer rows keyed by child_id.
     */
    public function __construct( $instrument, $children, $instance_id, $existing_answers = array() ) {
        $this->instrument       = (object) $instrument;
        $this->children         = is_array( $children ) ? $children : array();
        $this->instance_id      = absint( $instance_id );
        $this->existing_answers = is_array( $existing_answers ) ? $existing_answers : array();

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
     * Render the full children assessment form.
     *
     * @return string HTML output (does not echo).
     */
    public function render() {
        // ── Edge cases ──────────────────────────────────────────────────
        if ( empty( $this->children ) ) {
            ob_start();
            ?>
            <div class="hl-instrument-notice">
                <p><?php esc_html_e( 'No children in this classroom.', 'hl-core' ); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        if ( empty( $this->questions ) ) {
            ob_start();
            ?>
            <div class="hl-instrument-notice">
                <p><?php esc_html_e( 'No questions configured for this instrument.', 'hl-core' ); ?></p>
            </div>
            <?php
            return ob_get_clean();
        }

        ob_start();

        // ── Inline styles ───────────────────────────────────────────────
        $this->render_inline_styles();

        // ── Form open ───────────────────────────────────────────────────
        ?>
        <form method="post" action="" class="hl-instrument-form" id="hl-instrument-form-<?php echo esc_attr( $this->instance_id ); ?>">
            <?php wp_nonce_field( 'hl_save_children_assessment', 'hl_children_assessment_nonce' ); ?>
            <input type="hidden" name="hl_instrument_instance_id" value="<?php echo esc_attr( $this->instance_id ); ?>" />
            <input type="hidden" name="hl_requires_validation" id="hl_requires_validation_<?php echo esc_attr( $this->instance_id ); ?>" value="0" />

            <?php // ── Instrument header ───────────────────────────────── ?>
            <div class="hl-instrument-header">
                <h2><?php echo esc_html( $this->instrument->name ); ?></h2>
                <?php if ( ! empty( $this->instrument->version ) ) : ?>
                    <span class="hl-instrument-version">
                        <?php
                        /* translators: %s: instrument version */
                        printf( esc_html__( 'Version %s', 'hl-core' ), esc_html( $this->instrument->version ) );
                        ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php // ── Matrix table ────────────────────────────────────── ?>
            <div class="hl-instrument-table-wrap" style="overflow-x:auto;">
                <table class="hl-instrument-matrix">
                    <thead>
                        <tr>
                            <th class="hl-matrix-child-col"><?php esc_html_e( 'Child', 'hl-core' ); ?></th>
                            <?php foreach ( $this->questions as $question ) : ?>
                                <th class="hl-matrix-question-col">
                                    <?php echo esc_html( $question['prompt_text'] ); ?>
                                    <?php if ( ! empty( $question['required'] ) ) : ?>
                                        <span class="hl-required-marker" title="<?php esc_attr_e( 'Required', 'hl-core' ); ?>">*</span>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $row_index = 0;
                        foreach ( $this->children as $child ) :
                            $child       = (object) $child;
                            $child_id    = absint( $child->child_id );
                            $child_answers = isset( $this->existing_answers[ $child_id ] )
                                ? $this->existing_answers[ $child_id ]
                                : array();

                            // If answers_json is a nested key, extract it.
                            if ( isset( $child_answers['answers_json'] ) ) {
                                $decoded = $child_answers['answers_json'];
                                if ( is_string( $decoded ) ) {
                                    $decoded = json_decode( $decoded, true );
                                }
                                $child_answers = is_array( $decoded ) ? $decoded : array();
                            }

                            $row_class = ( $row_index % 2 === 0 ) ? 'hl-matrix-row-even' : 'hl-matrix-row-odd';
                            $row_index++;
                        ?>
                        <tr class="<?php echo esc_attr( $row_class ); ?>">
                            <td class="hl-matrix-child-cell">
                                <span class="hl-child-name">
                                    <?php echo esc_html( $child->first_name . ' ' . $child->last_name ); ?>
                                </span>
                                <?php if ( ! empty( $child->child_display_code ) ) : ?>
                                    <br /><small class="hl-child-code"><?php echo esc_html( $child->child_display_code ); ?></small>
                                <?php endif; ?>
                            </td>
                            <?php foreach ( $this->questions as $question ) :
                                $question_id   = $question['question_id'];
                                $question_type = $question['question_type'];
                                $is_required   = ! empty( $question['required'] );
                                $existing_val  = isset( $child_answers[ $question_id ] ) ? $child_answers[ $question_id ] : null;
                            ?>
                                <td class="hl-matrix-input-cell">
                                    <?php $this->render_input( $child_id, $question, $existing_val ); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php // ── Submit buttons ──────────────────────────────────── ?>
            <div class="hl-instrument-actions">
                <button type="submit"
                        name="hl_assessment_action"
                        value="draft"
                        class="button button-secondary hl-btn-save-draft"
                        id="hl-btn-draft-<?php echo esc_attr( $this->instance_id ); ?>">
                    <?php esc_html_e( 'Save Draft', 'hl-core' ); ?>
                </button>
                <button type="submit"
                        name="hl_assessment_action"
                        value="submit"
                        class="button button-primary hl-btn-submit-assessment"
                        id="hl-btn-submit-<?php echo esc_attr( $this->instance_id ); ?>">
                    <?php esc_html_e( 'Submit Assessment', 'hl-core' ); ?>
                </button>
            </div>
        </form>

        <?php // ── Inline JS for validation toggle ─────────────────────── ?>
        <?php $this->render_inline_script(); ?>

        <?php
        return ob_get_clean();
    }

    // ─── Private helpers ────────────────────────────────────────────────

    /**
     * Render the appropriate input element for a single question + child intersection.
     *
     * @param int          $child_id     The child's ID.
     * @param array        $question     The question definition.
     * @param mixed        $existing_val The previously saved value (if any).
     */
    private function render_input( $child_id, $question, $existing_val ) {
        $question_id   = $question['question_id'];
        $question_type = $question['question_type'];
        $is_required   = ! empty( $question['required'] );
        $field_name    = 'answers[' . $child_id . '][' . esc_attr( $question_id ) . ']';

        // Parse allowed_values: can be a comma-separated string or an array.
        $allowed_values = array();
        if ( isset( $question['allowed_values'] ) ) {
            if ( is_array( $question['allowed_values'] ) ) {
                $allowed_values = $question['allowed_values'];
            } elseif ( is_string( $question['allowed_values'] ) && $question['allowed_values'] !== '' ) {
                $allowed_values = array_map( 'trim', explode( ',', $question['allowed_values'] ) );
            }
        }

        // Required data attribute (toggled by JS).
        $req_attr = $is_required ? ' data-hl-required="1"' : '';

        switch ( $question_type ) {

            case 'likert':
                echo '<div class="hl-likert-group"' . $req_attr . '>';
                foreach ( $allowed_values as $val ) {
                    $checked = ( (string) $existing_val === (string) $val ) ? ' checked' : '';
                    $input_id = 'hl_' . $child_id . '_' . esc_attr( $question_id ) . '_' . sanitize_key( $val );
                    echo '<label class="hl-likert-label" for="' . esc_attr( $input_id ) . '" style="display:block;">';
                    echo '<input type="radio"'
                         . ' id="' . esc_attr( $input_id ) . '"'
                         . ' name="' . esc_attr( $field_name ) . '"'
                         . ' value="' . esc_attr( $val ) . '"'
                         . $checked
                         . ' class="hl-input-radio"'
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
                     . ' class="hl-input-text"'
                     . $req_attr
                     . ' />';
                break;

            case 'number':
                $val_attr = ( $existing_val !== null ) ? ' value="' . esc_attr( $existing_val ) . '"' : '';
                echo '<input type="number"'
                     . ' name="' . esc_attr( $field_name ) . '"'
                     . $val_attr
                     . ' class="hl-input-number"'
                     . $req_attr
                     . ' />';
                break;

            case 'single_select':
                echo '<select name="' . esc_attr( $field_name ) . '"'
                     . ' class="hl-input-select"'
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
                echo '<div class="hl-multiselect-group"' . $req_attr . '>';
                foreach ( $allowed_values as $val ) {
                    $checked  = in_array( (string) $val, array_map( 'strval', $existing_arr ), true ) ? ' checked' : '';
                    $input_id = 'hl_' . $child_id . '_' . esc_attr( $question_id ) . '_ms_' . sanitize_key( $val );
                    echo '<label class="hl-multiselect-label" for="' . esc_attr( $input_id ) . '" style="display:block;">';
                    echo '<input type="checkbox"'
                         . ' id="' . esc_attr( $input_id ) . '"'
                         . ' name="' . esc_attr( $ms_field_name ) . '"'
                         . ' value="' . esc_attr( $val ) . '"'
                         . $checked
                         . ' class="hl-input-checkbox"'
                         . ' /> ';
                    echo esc_html( $val );
                    echo '</label>';
                }
                echo '</div>';
                break;

            default:
                // Fallback: render as text input for unknown question types.
                $val_attr = ( $existing_val !== null ) ? ' value="' . esc_attr( $existing_val ) . '"' : '';
                echo '<input type="text"'
                     . ' name="' . esc_attr( $field_name ) . '"'
                     . $val_attr
                     . ' class="hl-input-text"'
                     . $req_attr
                     . ' />';
                break;
        }
    }

    /**
     * Render inline CSS for the instrument matrix.
     */
    private function render_inline_styles() {
        ?>
        <style>
            .hl-instrument-form {
                max-width: 100%;
                margin: 1.5em 0;
            }
            .hl-instrument-header {
                margin-bottom: 1em;
            }
            .hl-instrument-header h2 {
                margin: 0 0 0.25em 0;
                font-size: 1.4em;
            }
            .hl-instrument-version {
                color: #666;
                font-size: 0.85em;
            }
            .hl-instrument-notice {
                padding: 12px 16px;
                background: #fff8e1;
                border-left: 4px solid #ffb300;
                margin: 1em 0;
            }
            .hl-instrument-table-wrap {
                overflow-x: auto;
                margin-bottom: 1.5em;
                -webkit-overflow-scrolling: touch;
            }
            table.hl-instrument-matrix {
                border-collapse: collapse;
                width: 100%;
                min-width: 600px;
                font-size: 0.9em;
            }
            table.hl-instrument-matrix th,
            table.hl-instrument-matrix td {
                border: 1px solid #ddd;
                padding: 8px 10px;
                text-align: left;
                vertical-align: top;
            }
            table.hl-instrument-matrix thead th {
                background: #f1f1f1;
                font-weight: 600;
                position: sticky;
                top: 0;
                z-index: 2;
            }
            table.hl-instrument-matrix .hl-matrix-child-col,
            table.hl-instrument-matrix .hl-matrix-child-cell {
                position: sticky;
                left: 0;
                z-index: 1;
                background: inherit;
                min-width: 140px;
                max-width: 200px;
            }
            table.hl-instrument-matrix thead .hl-matrix-child-col {
                z-index: 3;
            }
            tr.hl-matrix-row-even td {
                background-color: #ffffff;
            }
            tr.hl-matrix-row-odd td {
                background-color: #f9f9f9;
            }
            tr.hl-matrix-row-even .hl-matrix-child-cell {
                background-color: #ffffff;
            }
            tr.hl-matrix-row-odd .hl-matrix-child-cell {
                background-color: #f9f9f9;
            }
            .hl-child-name {
                font-weight: 500;
            }
            .hl-child-code {
                color: #888;
                font-size: 0.85em;
            }
            .hl-required-marker {
                color: #d63638;
                font-weight: bold;
                margin-left: 2px;
            }
            .hl-likert-group,
            .hl-multiselect-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .hl-likert-label,
            .hl-multiselect-label {
                display: block;
                cursor: pointer;
                padding: 2px 0;
                font-size: 0.9em;
                white-space: nowrap;
            }
            .hl-input-text,
            .hl-input-number {
                width: 100%;
                max-width: 160px;
                padding: 4px 6px;
                border: 1px solid #ccc;
                border-radius: 3px;
            }
            .hl-input-select {
                width: 100%;
                max-width: 180px;
                padding: 4px 6px;
                border: 1px solid #ccc;
                border-radius: 3px;
            }
            .hl-instrument-actions {
                display: flex;
                gap: 12px;
                align-items: center;
                flex-wrap: wrap;
                padding: 1em 0;
            }
            .hl-btn-save-draft {
                min-width: 120px;
            }
            .hl-btn-submit-assessment {
                min-width: 160px;
            }
            /* Validation error styling */
            .hl-validation-error {
                border-color: #d63638 !important;
                box-shadow: 0 0 0 1px #d63638;
            }
        </style>
        <?php
    }

    /**
     * Render inline JavaScript for validation toggle between draft and submit.
     *
     * When "Submit Assessment" is clicked, required fields are enforced
     * via HTML5 required attribute. When "Save Draft" is clicked, required
     * attributes are removed so partial data can be saved.
     */
    private function render_inline_script() {
        $instance_id = esc_js( $this->instance_id );
        ?>
        <script>
        (function() {
            var formId        = 'hl-instrument-form-<?php echo $instance_id; ?>';
            var hiddenFieldId = 'hl_requires_validation_<?php echo $instance_id; ?>';
            var draftBtnId    = 'hl-btn-draft-<?php echo $instance_id; ?>';
            var submitBtnId   = 'hl-btn-submit-<?php echo $instance_id; ?>';

            var form        = document.getElementById(formId);
            var hiddenField = document.getElementById(hiddenFieldId);
            var draftBtn    = document.getElementById(draftBtnId);
            var submitBtn   = document.getElementById(submitBtnId);

            if (!form || !hiddenField || !draftBtn || !submitBtn) {
                return;
            }

            /**
             * Toggle HTML5 required attributes on all fields marked with
             * data-hl-required="1" and on inputs/selects within those containers.
             */
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
                    // For groups (likert radios, multiselect checkboxes), we
                    // require at least one radio/checkbox to be selected.
                    // HTML5 required on radio groups works natively if set on each radio.
                    var radios = el.querySelectorAll('input[type="radio"]');
                    radios.forEach(function(radio) {
                        if (enable) {
                            radio.setAttribute('required', 'required');
                        } else {
                            radio.removeAttribute('required');
                        }
                    });

                    // For multi_select, mark the first checkbox as required
                    // only if no checkbox in the group is checked.
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
            }

            // Draft: disable validation, allow partial save.
            draftBtn.addEventListener('click', function(e) {
                setValidation(false);
                // Allow normal form submission.
            });

            // Submit: enable validation, confirm dialog.
            submitBtn.addEventListener('click', function(e) {
                if (!confirm('<?php echo esc_js( __( 'Once submitted, answers cannot be changed. Continue?', 'hl-core' ) ); ?>')) {
                    e.preventDefault();
                    return;
                }
                setValidation(true);
                // Allow the browser's native validation to run.
                // If validation fails, form won't submit.
            });

            // Handle multi_select required toggling when checkboxes change.
            form.addEventListener('change', function(e) {
                if (e.target.type !== 'checkbox') return;
                if (hiddenField.value !== '1') return;

                var group = e.target.closest('.hl-multiselect-group[data-hl-required="1"]');
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

        })();
        </script>
        <?php
    }
}
