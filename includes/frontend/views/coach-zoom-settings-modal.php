<?php
/**
 * Coach Zoom Meeting Settings — Modal view.
 *
 * Expects in scope:
 *   $modal_coach_user_id (int)
 *   $resolved (array)
 *   $overrides (array)
 *   $defaults (array)
 *
 * @package HL_Core
 */
if ( ! defined( 'ABSPATH' ) ) exit;
unset( $overrides['_meta'] );

// Auto-open ONLY in admin context (admin editing a different user). PHP-controlled,
// not querystring-keyed (avoids spurious opens on a coach's own dashboard).
$is_admin_editing_other = ( get_current_user_id() !== (int) $modal_coach_user_id ) && current_user_can( 'manage_hl_core' );
?>
<div class="hlczs-modal-backdrop" hidden></div>
<div class="hlczs-modal" role="dialog" aria-modal="true" aria-labelledby="hlczs-modal-title" data-auto-open="<?php echo $is_admin_editing_other ? '1' : '0'; ?>" hidden>
    <div class="hlczs-modal-header">
        <h2 id="hlczs-modal-title"><?php esc_html_e( 'My Meeting Settings', 'hl-core' ); ?></h2>
        <button type="button" class="hlczs-modal-close" aria-label="<?php esc_attr_e( 'Close', 'hl-core' ); ?>">&times;</button>
    </div>

    <form id="hlczs-form"
          data-coach-id="<?php echo esc_attr( $modal_coach_user_id ); ?>"
          data-nonce="<?php echo esc_attr( wp_create_nonce( 'hl_save_coach_zoom_settings' ) ); ?>">

        <div class="hlczs-banner" role="alert" hidden></div>

        <?php
        $rows = array(
            'waiting_room'     => __( 'Waiting room', 'hl-core' ),
            'mute_upon_entry'  => __( 'Mute upon entry', 'hl-core' ),
            'join_before_host' => __( 'Join before host', 'hl-core' ),
        );
        foreach ( $rows as $field => $label ) :
            $resolved_val = ! empty( $resolved[ $field ] );
            $is_override  = array_key_exists( $field, $overrides );
            ?>
            <div class="hlczs-row" data-field="<?php echo esc_attr( $field ); ?>">
                <div class="hlczs-row-label"><?php echo esc_html( $label ); ?></div>
                <div class="hlczs-row-control">
                    <button type="button"
                            class="hlczs-toggle"
                            role="switch"
                            aria-pressed="<?php echo $resolved_val ? 'true' : 'false'; ?>"
                            data-field="<?php echo esc_attr( $field ); ?>"
                            data-default-value="<?php echo $defaults[ $field ] ? '1' : '0'; ?>">
                        <span class="hlczs-toggle-track"><span class="hlczs-toggle-thumb"></span></span>
                        <span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
                    </button>
                </div>
                <div class="hlczs-row-meta">
                    <span class="hlczs-row-caption" aria-live="polite">
                        <?php
                        echo esc_html( $is_override
                            ? __( 'Using your override.', 'hl-core' )
                            : __( 'Using the company default.', 'hl-core' )
                        );
                        ?>
                    </span>
                    <button type="button"
                            class="hlczs-row-reset"
                            data-field="<?php echo esc_attr( $field ); ?>"
                            <?php echo $is_override ? '' : 'hidden'; ?>>
                        <?php esc_html_e( 'Reset to default', 'hl-core' ); ?>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>

        <?php
        // Alternative hosts row — same chrome.
        $alt_default        = $defaults['alternative_hosts'];
        $alt_default_label  = $alt_default !== ''
            ? sprintf( '[%s]', $alt_default )
            : __( '(no alternative hosts)', 'hl-core' );
        $alt_override_value = array_key_exists( 'alternative_hosts', $overrides ) ? $overrides['alternative_hosts'] : null;
        $alt_state          = $alt_override_value === null ? 'use_default'
                              : ( $alt_override_value === '' ? 'override_none' : 'override_emails' );
        ?>
        <div class="hlczs-row hlczs-row-althosts" data-field="alternative_hosts">
            <div class="hlczs-row-label"><?php esc_html_e( 'Alternative hosts', 'hl-core' ); ?></div>
            <div class="hlczs-row-control">
                <fieldset>
                    <legend class="screen-reader-text"><?php esc_html_e( 'Alternative hosts mode', 'hl-core' ); ?></legend>
                    <label><input type="radio" name="alt_hosts_mode" value="use_default" <?php checked( $alt_state, 'use_default' ); ?>>
                        <?php
                        printf(
                            /* translators: %s = formatted default value */
                            esc_html__( 'Use the company default %s', 'hl-core' ),
                            esc_html( $alt_default_label )
                        );
                        ?>
                    </label><br>
                    <label><input type="radio" name="alt_hosts_mode" value="override_none" <?php checked( $alt_state, 'override_none' ); ?>>
                        <?php esc_html_e( 'Override: no alternative hosts', 'hl-core' ); ?>
                    </label><br>
                    <label>
                        <input type="radio" name="alt_hosts_mode" value="override_emails" <?php checked( $alt_state, 'override_emails' ); ?>>
                        <?php esc_html_e( 'Override with these emails:', 'hl-core' ); ?>
                    </label>
                    <textarea
                        id="hlczs-alt-hosts-textarea"
                        name="alternative_hosts"
                        rows="2" cols="40" maxlength="1024"
                        placeholder="<?php esc_attr_e( 'comma-separated emails', 'hl-core' ); ?>"
                        <?php echo $alt_state === 'override_emails' ? '' : 'disabled'; ?>><?php echo esc_textarea( $alt_state === 'override_emails' ? $alt_override_value : '' ); ?></textarea>
                </fieldset>
            </div>
            <div class="hlczs-row-meta">
                <button type="button"
                        class="hlczs-row-reset"
                        data-field="alternative_hosts"
                        <?php echo $alt_state === 'use_default' ? 'hidden' : ''; ?>>
                    <?php esc_html_e( 'Reset to default', 'hl-core' ); ?>
                </button>
            </div>
        </div>

        <!-- Read-only "Set by your administrator" section (admin-only fields) -->
        <section class="hlczs-readonly" aria-labelledby="hlczs-readonly-title">
            <h3 id="hlczs-readonly-title"><?php esc_html_e( 'Set by your administrator', 'hl-core' ); ?></h3>
            <p class="hlczs-readonly-help"><?php esc_html_e( 'These settings apply to all coaching sessions. Contact your administrator to change.', 'hl-core' ); ?></p>
            <ul>
                <li>
                    <strong><?php esc_html_e( 'Require passcode:', 'hl-core' ); ?></strong>
                    <?php echo esc_html( ! empty( $resolved['password_required'] ) ? __( 'On', 'hl-core' ) : __( 'Off', 'hl-core' ) ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Require Zoom sign-in:', 'hl-core' ); ?></strong>
                    <?php echo esc_html( ! empty( $resolved['meeting_authentication'] ) ? __( 'On', 'hl-core' ) : __( 'Off', 'hl-core' ) ); ?>
                </li>
            </ul>
        </section>

        <div class="hlczs-modal-footer">
            <button type="button" class="button hlczs-reset-all"><?php esc_html_e( 'Reset all to defaults', 'hl-core' ); ?></button>
            <button type="submit" class="button button-primary hlczs-save"><?php esc_html_e( 'Save', 'hl-core' ); ?></button>
        </div>
    </form>
</div>

<!-- "Reset all" confirm-modal-in-modal (styled, NOT native confirm) -->
<div class="hlczs-confirm-backdrop" hidden></div>
<div class="hlczs-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="hlczs-confirm-title" hidden>
    <h3 id="hlczs-confirm-title"><?php esc_html_e( 'Reset all settings?', 'hl-core' ); ?></h3>
    <p><?php esc_html_e( 'Reset all your meeting settings to the company defaults? Your overrides will be cleared.', 'hl-core' ); ?></p>
    <div class="hlczs-confirm-actions">
        <button type="button" class="button hlczs-confirm-cancel"><?php esc_html_e( 'Cancel', 'hl-core' ); ?></button>
        <button type="button" class="button button-primary hlczs-confirm-ok"><?php esc_html_e( 'Reset all', 'hl-core' ); ?></button>
    </div>
</div>
